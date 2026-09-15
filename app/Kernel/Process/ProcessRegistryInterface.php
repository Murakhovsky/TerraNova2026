<?php
declare(strict_types=1);

namespace Kernel\Process;

interface ProcessRegistryInterface
{
    /** @return list<ProcessDefinition> */
    public function all(): array;

    public function has(string $id): bool;

    public function get(string $id): ?ProcessDefinition;
}
