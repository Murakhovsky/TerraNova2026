<?php
declare(strict_types=1);

namespace Domains\Sales\Diagnostics\Methodology;

use InvalidArgumentException;

final class SalesDiagnosticCatalog
{
    /** @var array<string, SalesDiagnosticDefinition> */
    private array $definitions = [];

    /** @param iterable<SalesDiagnosticDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function register(SalesDiagnosticDefinition $definition): void
    {
        $key = $definition->id() . '@' . $definition->version();
        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException('Duplicate Sales diagnostic methodology: ' . $key);
        }
        $this->definitions[$key] = $definition;
    }

    public function get(string $id, int $version): ?SalesDiagnosticDefinition
    {
        return $this->definitions[$id . '@' . $version] ?? null;
    }
}
