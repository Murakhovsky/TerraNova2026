<?php
declare(strict_types=1);

namespace App\Engineering\Application\Context;

interface EngineeringDatabaseSchemaProviderInterface
{
    /**
     * Return a bounded, read-only schema snapshot for architectural analysis.
     *
     * @param list<string> $hints
     * @return array<string,mixed>
     */
    public function snapshot(array $hints = []): array;
}
