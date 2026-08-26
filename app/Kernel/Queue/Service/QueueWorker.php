<?php
declare(strict_types=1);
namespace Kernel\Queue\Service;

use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use RuntimeException;
use Throwable;

final readonly class QueueWorker
{
    /** @param list<JobHandlerInterface> $handlers */
    public function __construct(
        private JobQueueInterface $queue,
        private array $handlers,
        private ?MetricsRecorderInterface $metrics = null,
        private ?StructuredLoggerInterface $logger = null,
    ) {}
    public function runOne(string $workerId): bool
    {
        $this->queue->recoverTimedOut();
        $job = $this->queue->claim($workerId);
        if ($job === null) return false;
        try {
            foreach ($this->handlers as $handler) {
                if ($handler->supports($job->type)) {
                    $handler->handle($job);
                    $this->queue->complete($job);
                    $this->observe('cos.jobs.completed', $job->organizationId, $job->type);
                    return true;
                }
            }
            throw new RuntimeException('No handler for job ' . $job->type);
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
