<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DateTimeImmutable;

/**
 * A Domain-owned, read-only source of business outcome facts.
 * Callers cannot pass values or evidence references to a durable evaluation.
 */
interface GoalOutcomeEvidenceProviderInterface
{
    public function domain(): string;

    public function supports(string $criterionId): bool;

    /**
     * @return array{value:int|float|string|bool,evidence:list<string>,source:string,window_start:string,window_end:string}
     */
    public function observe(
        string $organizationId,
        string $criterionId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;
}
