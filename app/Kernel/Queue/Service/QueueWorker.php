<?php
declare(strict_types=1);
namespace Kernel\Queue\Service;

use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use Kernel\Operations\Service\PeriodicMaintenanceGate;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Contract\JobQueueInterface;
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
            $this->queue->fail($job, $exception->getMessage());
            $this->observe('cos.jobs.failed', $job->organizationId, $job->type, $exception);
            return true;
        }
    }

    private function observe(string $metric, string $organizationId, string $jobType, ?Throwable $error = null): void
    {
        try {
            $this->metrics?->record($metric, 1, $organizationId, ['job_type' => $jobType]);
            if ($error !== null) {
                $this->logger?->log('error', 'COS job failed.', [
                    'organization_id' => $organizationId,
                    'job_type' => $jobType,
                    'exception' => $error::class,
                    'error' => $error->getMessage(),
                ]);
            }
        } catch (Throwable) {
            // Telemetry is best-effort and must not alter job state.
        }
    }
}
