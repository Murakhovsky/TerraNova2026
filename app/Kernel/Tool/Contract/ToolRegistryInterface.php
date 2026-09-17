<?php
declare(strict_types=1);

namespace Kernel\Tool\Contract;

use Kernel\Tool\Model\ToolDefinition;

interface ToolRegistryInterface
{
    public function find(string $name): ?ToolInterface;

    /** @return list<ToolDefinition> */
    public function definitions(): array;
}
