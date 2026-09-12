<?php
declare(strict_types=1);

namespace Kernel\Module;

use RuntimeException;
use Throwable;

final readonly class ModuleDiscovery
{
    public function __construct(
        private string $domainsPath,
        private ?string $compiledCachePath = null,
    ) {
    }

    /** @return list<ModuleDefinition> */
    public function discover(): array
    {
        $paths = $this->modulePaths();
        $signature = $this->sourceSignature($paths);

        $cached = $this->loadCompiled($signature);
        if ($cached !== null) {
            return $cached;
        }

        $rawDefinitions = [];
        $definitions = [];
        foreach ($paths as $path) {
            $definition = require $path;
            if (!is_array($definition)) {
                throw new RuntimeException(sprintf('Invalid module definition: %s.', $path));
            }
            $rawDefinitions[] = ['source_path' => $path, 'definition' => $definition];
            $definitions[] = ModuleDefinition::fromArray($definition, $path);
        }

        $definitions = $this->sortDefinitions($definitions);
        $this->writeCompiled($signature, $rawDefinitions);

        return $definitions;
    }

    /** @return list<string> */
    private function modulePaths(): array
    {
        $paths = glob(rtrim($this->domainsPath, '/') . '/*/module.php') ?: [];
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @param list<string> $paths */
    private function sourceSignature(array $paths): string
    {
        $parts = [];
        foreach ($paths as $path) {
            $parts[] = implode(':', [
                $path,
                (string) (@filesize($path) ?: 0),
                (string) (@filemtime($path) ?: 0),
            ]);
        }
        return hash('sha256', implode('|', $parts));
    }

    /** @return list<ModuleDefinition>|null */
    private function loadCompiled(string $signature): ?array
    {
        if ($this->compiledCachePath === null || !is_file($this->compiledCachePath)) {
            return null;
        }

        try {
            $cache = require $this->compiledCachePath;
        } catch (Throwable) {
            return null;
        }
        if (!is_array($cache)
            || ($cache['signature'] ?? null) !== $signature
            || !is_array($cache['definitions'] ?? null)) {
            return null;
        }

        $definitions = [];
        foreach ($cache['definitions'] as $entry) {
            if (!is_array($entry)
                || !is_array($entry['definition'] ?? null)
                || !is_string($entry['source_path'] ?? null)) {
                return null;
            }
            $definitions[] = ModuleDefinition::fromArray($entry['definition'], $entry['source_path']);
        }

        return $this->sortDefinitions($definitions);
    }

    /** @param list<array{source_path: string, definition: array<string, mixed>}> $definitions */
    private function writeCompiled(string $signature, array $definitions): void
    {
        if ($this->compiledCachePath === null || !$this->isCacheable($definitions)) {
            return;
        }

        $directory = dirname($this->compiledCachePath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $payload = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'signature' => $signature,
            'definitions' => $definitions,
        ], true) . ";\n";
        $temporary = $this->compiledCachePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return;
        }
        if (!@rename($temporary, $this->compiledCachePath)) {
            @unlink($temporary);
        }
    }

    private function isCacheable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if ((!is_int($key) && !is_string($key)) || !$this->isCacheable($item)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<ModuleDefinition> $definitions @return list<ModuleDefinition> */
    private function sortDefinitions(array $definitions): array
    {
        usort(
            $definitions,
            static fn (ModuleDefinition $left, ModuleDefinition $right): int
                => $left->manifest->id <=> $right->manifest->id,
        );
        return $definitions;
    }
}
