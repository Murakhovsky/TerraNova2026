<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\OpportunityCandidate;
use Domains\Growth\Domain\Signal;

interface GrowthRepositoryInterface
{
    public function createSignal(Signal $signal, int $actorId): void;

    /** @return array<string,mixed>|null */
    public function viewSignal(string $organizationId, string $signalId): ?array;

    public function createCandidate(OpportunityCandidate $candidate, int $actorId): void;

    public function lockCandidate(string $organizationId, string $candidateId): OpportunityCandidate;

    public function updateCandidate(OpportunityCandidate $candidate, int $actorId): void;

    /** @return array<string,mixed>|null */
    public function viewCandidate(string $organizationId, string $candidateId): ?array;
}
