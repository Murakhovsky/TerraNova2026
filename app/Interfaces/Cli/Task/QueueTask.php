<?php
declare(strict_types=1);

namespace Interfaces\Cli\Task;

use Kernel\Queue\Service\QueueWorker;
use Kernel\Queue\Contract\JobQueueInterface;
use Phalcon\Cli\Task;

final class QueueTask extends Task
{
    public function runAction(?string $workerId = null, int $limit = 100): void
    {
        /** @var QueueWorker $worker */
        $worker = $this->getDI()->getShared('cosQueueWorker');
        $workerId ??= gethostname() . '-' . getmypid();
        $processed = 0;
        while ($processed < max(1, min($limit, 1000)) && $worker->runOne($workerId)) {
            $processed++;
        }
        echo sprintf('Processed: %d', $processed) . PHP_EOL;
    }

    public function replayDeadAction(?string $organizationId = null, ?string $jobId = null): void
    {
        /** @var JobQueueInterface $queue */
        $queue = $this->getDI()->getShared('cosJobQueue');
        echo sprintf('Dead jobs replayed: %d', $queue->replayDead($organizationId ?: null, $jobId ?: null)) . PHP_EOL;
    }
}
