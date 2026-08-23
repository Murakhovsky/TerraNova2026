<?php
declare(strict_types=1);

namespace Terra\Modules\Cli\Tasks;

use Kernel\Action\Service\ActionService;
use Phalcon\Cli\Task;

final class ActionTask extends Task
{
    public function runAction(?string $workerId = null, int $limit = 1): void
    {
        /** @var ActionService $service */
        $service = $this->getDI()->getShared('cosActionService');
        $workerId ??= gethostname() . '-' . getmypid();
        $service->recoverStale();
        $processed = 0;

        while ($processed < max(1, min($limit, 100))) {
            $action = $service->executeNext($workerId);
            if ($action === null) break;
            $processed++;
            echo sprintf('%s %s %s', $action->id, $action->type, $action->status->value) . PHP_EOL;
        }

        echo sprintf('Processed: %d', $processed) . PHP_EOL;
    }
}
