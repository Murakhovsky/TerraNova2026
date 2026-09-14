<?php
declare(strict_types=1);

namespace Kernel\Operations\Service;

use Kernel\Event\Service\OutboxPublisher;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use Kernel\Queue\Service\QueueWorker;
use Throwable;

final readonly class WorkerSupervisor
{
    public function __construct(
        private OutboxPublisher $outbox,
        private QueueWorker $queue,
        private MetricsRecorderInterface $metrics,
        private StructuredLoggerInterface $logger,
    ) {
    }

    /** @return array{outbox: int, jobs: int, errors: int} */
    public function run(string $workerId, int $maxRuntimeSeconds = 0, int $idleMilliseconds = 250): array
    {
        $started = microtime(true);
        $result = ['outbox' => 0, 'jobs' => 0, 'errors' => 0];
        $idleMilliseconds = max(25, min($idleMilliseconds, 5000));
        while ($maxRuntimeSeconds <= 0 || microtime(true) - $started < $maxRuntimeSeconds) {
            $worked = false;
            try {
                if ($this->outbox->runOne($workerId . '-events')) {
                    $result['outbox']++;
                    $worked = true;
                }
                if ($this->queue->runOne($workerId . '-jobs')) {
                    $result['jobs']++;
                    $worked = true;
                }
            } catch (Throwable $error) {
                $result['errors']++;
                $this->metrics->record('cos.worker.errors', 1, labels: ['exception' => $error::class]);
                $this->logger->log('error', 'COS worker iteration failed.', [
                    'worker_id' => $workerId,
                    'exception' => $error::class,
                    'error' => $error->getMessage(),
                ]);
            }
            if (!$worked) usleep($idleMilliseconds * 1000);
        }
        return $result;
    }
}
