<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowRunIdentity;

/** Domain-specific independent authoritative fact verification (no Workflow writes). */
interface WorkflowNonActionEvidenceAdapterInterface
{
    public function kind(): string;
    public function ownerDomain(): string;

    /** @param array<string,mixed> $evidence */
    public function assertVerified(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $reference,
        array $evidence,
    ): void;
}
