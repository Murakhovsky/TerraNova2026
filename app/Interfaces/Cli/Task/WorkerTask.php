<?php
declare(strict_types=1);

namespace Interfaces\Cli\Task;

use Kernel\Operations\Service\WorkerSupervisor;
use Phalcon\Cli\Task;

final class WorkerTask extends Task
{
    public function runAction(?string $workerId = null, int $runtimeSeconds = 0, int $idleMilliseconds = 250): void
    {
        /** @var WorkerSupervisor $supervisor */
        $supervisor = $this->getDI()->getShared('cosWorkerSupervisor');
        $workerId ??= gethostname() . '-cos-' . getmypid();
        echo json_encode(
            $supervisor->run($workerId, max(0, $runtimeSeconds), $idleMilliseconds),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL;
    }
}
