<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Domain-owned proof read from persisted sources before technical mutations. */
interface WorkflowTechnicalExternalEvidenceInterface
{
    public function assertBegin(
        WorkflowRunIdentity $identity,
        WorkflowDefinitionSnapshot $definition,
        string $inputDigest,
        string $actorId,
        string $correlationId,
        string $workerId
    ): void;

    public function assertCompletion(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $claimToken,
        string $receipt,
        string $correlationId
    ): void;

    public function assertQuarantine(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $claimToken
    ): void;
}
