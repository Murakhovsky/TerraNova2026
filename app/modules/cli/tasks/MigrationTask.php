<?php
declare(strict_types=1);

namespace Terra\Modules\Cli\Tasks;

use Infrastructure\Database\Migration\MigrationRunner;
use Phalcon\Cli\Task;

final class MigrationTask extends Task
{
    public function upAction(): void
    {
        /** @var MigrationRunner $runner */
        $runner = $this->getDI()->getShared('cosMigrationRunner');
        echo json_encode($runner->migrate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    public function statusAction(): void
    {
        /** @var MigrationRunner $runner */
        $runner = $this->getDI()->getShared('cosMigrationRunner');
        echo json_encode($runner->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
