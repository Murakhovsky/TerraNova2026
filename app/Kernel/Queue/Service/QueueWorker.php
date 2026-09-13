<?php
declare(strict_types=1);
namespace Kernel\Queue\Service;

use Kernel\Execution\ExecutionFailureClassifier;
use Kernel\Execution\ExecutionFailureKind;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use Kernel\Operations\Service\PeriodicMaintenanceGate;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Contract\RetryAwareJobQueueInterface;
use Kernel\Queue\Job;
use Throwable;

final class QueueWorker
{
    private readonly JobHandlerRegistry $handlers;
    private readonly PeriodicMaintenanceGate $recoveryGate;

    /** @param JobHandlerRegistry|list<JobHandlerInterface> $handlers */
    public function __construct(
        private readonly JobQueueInterface $queue,
        JobHandlerRegistry|array $handlers,
        private readonly ?MetricsRecorderInterface $metrics = null,
        private readonly ?StructuredLoggerInterface $logger = null,
        int $recoveryIntervalSeconds = 30,
    ) {
        $this->handlers = $handlers instanceof JobHandlerRegistry
            ? $handlers
            : new JobHandlerRegistry($handlers);
        $this->recoveryGate = new PeriodicMaintenanceGate($recoveryIntervalSeconds);
    }

    public function runOne(string $workerId): bool
    {
        if ($this->recoveryGate->due()) {
            $this->queue->recoverTimedOut();
        }

        $job = $this->queue->claim($workerId);
        if ($job === null) return false;

        $this->observeAdmission($job);
        $started = hrtime(true);
        try {
            $handler = $this->handlers->handlerFor($job->type);
            $handler->handle($job);
            $this->queue->complete($job);
            $this->observeResult($job, $started);
            return true;
        } catch (Throwable $exception) {
            $failureKind = ExecutionFailureClassifier::classify($exception);
            if ($this->queue instanceof RetryAwareJobQueueInterface) {
                $this->queue->failWithRetryPolicy($job, $exception->getMessage(), $failureKind->retryable());
            } else {
                $this->queue->fail($job, $exception->getMessage());
            }
            $this->observeResult($job, $started, $exception, $failureKind);
            return true;
        }
    }

    private function observeAdmission(Job $job): void
    {
        try {
            $labels = ['runtime' => 'job', 'job_type' => $job->type];
            $queueWaitMs = $job->queueWaitMilliseconds();
            if ($queueWaitMs !== null) {
                $this->metrics?->record('cos.execution.queue_wait_ms', $queueWaitMs, $job->organizationId, $labels);
            }
            $this->metrics?->record('cos.execution.lock_wait_ms', max(0.0, $job->lockWaitMs), $job->organizationId, [
                ...$labels,
                'lock_scope' => 'tenant_job',
            ]);
            if ($job->attempts > 1) {
                $this->metrics?->record('cos.execution.retry_count', 1, $job->organizationId, $labels);
            }
        } catch (Throwable) {
            // Telemetry is best-effort and must not alter queue admission.
        }
    }

    private function observeResult(
        Job $job,
        int $started,
        ?Throwable $error = null,
        ?ExecutionFailureKind $failureKind = null,
    ): void {
        try {
            $outcome = $error === null ? 'success' : 'failure';
            $labels = [
                'runtime' => 'job',
                'job_type' => $job->type,
                'outcome' => $outcome,
            ];
            $durationMs = max(0.0, (hrtime(true) - $started) / 1_000_000);

            // Legacy counters remain for existing dashboards.
            $this->metrics?->record($error === null ? 'cos.jobs.completed' : 'cos.jobs.failed', 1, $job->organizationId, [
                'job_type' => $job->type,
            ]);
            $this->metrics?->record('cos.execution.execution_ms', $durationMs, $job->organizationId, $labels);
            $this->metrics?->record('cos.execution.throughput', 1, $job->organizationId, $labels);

            if ($failureKind !== null) {
                $failureLabels = [
                    ...$labels,
                    'failure_kind' => $failureKind->value,
                    'retryable' => $failureKind->retryable() ? '1' : '0',
                ];
                $this->metrics?->record('cos.execution.failures', 1, $job->organizationId, $failureLabels);
            }

            if ($error !== null) {
                $this->logger?->log('error', 'COS job failed.', [
                    'organization_id' => $job->organizationId,
                    'correlation_id' => $job->correlationId,
                    'job_id' => $job->id,
                    'job_type' => $job->type,
                    'attempt' => $job->attempts,
                    'exception' => $error::class,
                    'error' => $error->getMessage(),
                    'failure_kind' => $failureKind?->value,
                    'retryable' => $failureKind?->retryable(),
                ]);
            }
        } catch (Throwable) {
            // Telemetry is best-effort and must not alter job state.
        }
    }
}
