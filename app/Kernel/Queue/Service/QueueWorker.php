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
        try {
            $handler = $this->handlers->handlerFor($job->type);
            $handler->handle($job);
            $this->queue->complete($job);
            $this->observe('cos.jobs.completed', $job->organizationId, $job->type);
            return true;
        } catch (Throwable $exception) {
            $failureKind = ExecutionFailureClassifier::classify($exception);
            if ($this->queue instanceof RetryAwareJobQueueInterface) {
                $this->queue->failWithRetryPolicy($job, $exception->getMessage(), $failureKind->retryable());
            } else {
                $this->queue->fail($job, $exception->getMessage());
            }
            $this->observe('cos.jobs.failed', $job->organizationId, $job->type, $exception, $failureKind);
            return true;
        }
    }

    private function observe(
        string $metric,
        string $organizationId,
        string $jobType,
        ?Throwable $error = null,
        ?ExecutionFailureKind $failureKind = null,
    ): void {
        try {
            $labels = ['job_type' => $jobType];
            if ($failureKind !== null) {
                $labels['failure_kind'] = $failureKind->value;
                $labels['retryable'] = $failureKind->retryable() ? '1' : '0';
            }
            $this->metrics?->record($metric, 1, $organizationId, $labels);
            if ($error !== null) {
                $this->logger?->log('error', 'COS job failed.', [
                    'organization_id' => $organizationId,
                    'job_type' => $jobType,
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
