<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DateTimeImmutable;
use Kernel\Tenant\Model\TenantContext;

/**
 * Run-linked evidence is NOT interchangeable with temporal counts. Implementors
 * verify stored linkage to independently attested Actions of the given Run.
 */
interface RunScopedGoalOutcomeEvidenceProviderInterface extends GoalOutcomeEvidenceProviderInterface
{
    /** @return array<string,mixed> */
    public function observeRun(
        TenantContext $actor,
        string $runId,
        string $criterionId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;
}
