<?php
declare(strict_types=1);

namespace Terra\Modules\Cli\Tasks;

use Kernel\Queue\Service\QueueWorker;
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
}
