<?php
declare(strict_types=1);

namespace Infrastructure\Process;

use JsonException;
use Kernel\Process\ProcessDefinition;
use Kernel\Process\ProcessRegistryInterface;
use RuntimeException;

final class JsonProcessRegistry implements ProcessRegistryInterface
{
    /** @var array<string,ProcessDefinition> */
    private array $definitions;

    public function __construct(private readonly string $directory)
    {
        $this->definitions = $this->load();
    }

    /** @return list<ProcessDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function get(string $id): ?ProcessDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    /** @return array<string,ProcessDefinition> */
    private function load(): array
    {
        if (!is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Process Registry directory does not exist: %s.', $this->directory));
        }
        $paths = glob(rtrim($this->directory, '/\\') . '/*.json') ?: [];
        sort($paths, SORT_STRING);
        if ($paths === []) {
            throw new RuntimeException(sprintf('Process Registry is empty: %s.', $this->directory));
        }

        $definitions = [];
        $workflows = [];
        foreach ($paths as $path) {
            $raw = file_get_contents($path);
            if ($raw === false) throw new RuntimeException(sprintf('Cannot read Process Registry definition: %s.', $path));
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException(sprintf('Invalid Process Registry JSON %s: %s', $path, $error->getMessage()), 0, $error);
            }
            if (!is_array($data)) throw new RuntimeException(sprintf('Process Registry definition must be an object: %s.', $path));

            $definition = ProcessDefinition::fromArray($data);
            if (isset($definitions[$definition->id])) throw new RuntimeException(sprintf('Duplicate Process Registry id: %s.', $definition->id));
            if (isset($workflows[$definition->workflow])) throw new RuntimeException(sprintf('Workflow %s is mapped by more than one process.', $definition->workflow));
            $definitions[$definition->id] = $definition;
            $workflows[$definition->workflow] = true;
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
    }
}
