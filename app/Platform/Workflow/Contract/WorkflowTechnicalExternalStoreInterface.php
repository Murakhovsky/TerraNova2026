<?php
declare(strict_types=1);

namespace Platform\Workflow\Contract;

use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Internal technical Agent-run admission into the SAME authoritative
 * Platform Workflow Store. A Domain gateway must verify tenant, source,
 * rollout and side-effect eligibility before calling this contract.
 *
 * Never a general-purpose Action bypass. Failed/uncertain external work
 * is quarantined and cannot be automatically replayed.
 */
interface WorkflowTechnicalExternalStoreInterface
{
    public function beginGuardedExternal(
        WorkflowRunIdentity $identity,
        WorkflowDefinitionSnapshot $definition,
        string $inputDigest,
        string $actorId,
        string $correlationId,
        string $workerId,
        int $leaseSeconds
    ): string;

    public function completeGuardedExternal(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $claimToken,
        string $receipt,
        string $correlationId
    ): void;

    public function quarantineGuardedExternal(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $claimToken
    ): void;
}
