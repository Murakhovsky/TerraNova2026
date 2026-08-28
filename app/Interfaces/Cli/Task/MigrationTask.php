<?php
declare(strict_types=1);

namespace Interfaces\Cli\Task;

use Kernel\Database\MigrationRunnerInterface;
use Phalcon\Cli\Task;

final class MigrationTask extends Task
{
    public function upAction(): void
    {
        /** @var MigrationRunnerInterface $runner */
        $runner = $this->getDI()->getShared('cosMigrationRunner');
        echo json_encode($runner->migrate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    public function statusAction(): void
    {
        /** @var MigrationRunnerInterface $runner */
        $runner = $this->getDI()->getShared('cosMigrationRunner');
        echo json_encode($runner->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
