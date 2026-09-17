<?php
declare(strict_types=1);

namespace Kernel\Tool\Service;

use InvalidArgumentException;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Contract\ToolRegistryInterface;
use Kernel\Tool\Model\ToolDefinition;

final class ToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @param iterable<ToolInterface> $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(ToolInterface $tool): void
    {
        $name = $tool->definition()->name();
        if (isset($this->tools[$name])) {
            throw new InvalidArgumentException(sprintf('Tool "%s" is already registered.', $name));
        }

        $this->tools[$name] = $tool;
    }

    public function find(string $name): ?ToolInterface
    {
        return $this->tools[strtolower(trim($name))] ?? null;
    }

    /** @return list<ToolDefinition> */
    public function definitions(): array
    {
        return array_values(array_map(
            static fn (ToolInterface $tool): ToolDefinition => $tool->definition(),
            $this->tools,
        ));
    }
}
