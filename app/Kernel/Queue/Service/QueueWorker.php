<?php
declare(strict_types=1);
namespace Kernel\Queue\Service;

use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use RuntimeException;
use Throwable;

final readonly class QueueWorker
{
    /** @param list<JobHandlerInterface> $handlers */
    public function __construct(private JobQueueInterface $queue, private array $handlers) {}
    public function runOne(string $workerId): bool
    {
        $this->queue->recoverTimedOut();
        $job = $this->queue->claim($workerId);
        if ($job === null) return false;
        try {
            foreach ($this->handlers as $handler) {
                if ($handler->supports($job->type)) {
                    $handler->handle($job); $this->queue->complete($job); return true;
                }
            }
            throw new RuntimeException('No handler for job ' . $job->type);
        } catch (Throwable $exception) {
            $this->queue->fail($job, $exception->getMessage()); return true;
        }
    }
}
