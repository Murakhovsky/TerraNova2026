<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

interface EngineeringTaskStoreInterface
{
    public function createFromManager(string $featureId, array $tasks): void;

    /** @return list<array<string,mixed>> */
    public function forFeature(string $featureId): array;
}
