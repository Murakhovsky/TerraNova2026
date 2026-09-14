<?php
declare(strict_types=1);

namespace Kernel\Database;

interface MigrationRunnerInterface
{
    /** @return array{applied: list<string>, skipped: list<string>} */
    public function migrate(): array;

    public function status(): array;
}
