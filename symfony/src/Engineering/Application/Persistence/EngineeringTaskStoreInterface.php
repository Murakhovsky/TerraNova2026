<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

interface EngineeringTaskStoreInterface
{
    public function createFromProductRequirements(string $featureId, array $tasks): void;

/** @deprecated V1 compatibility alias. */
public function createFromManager(string $featureId, array $tasks): void;
    public function markRole(string $featureId, \App\Engineering\Domain\Agent\AgentRole $role, string $status, ?array $result = null): void;
    public function hasIncomplete(string $featureId): bool;

    /** @return list<array<string,mixed>> */
    public function forFeature(string $featureId): array;
}
