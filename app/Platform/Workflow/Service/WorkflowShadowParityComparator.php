<?php
declare(strict_types=1);

namespace Platform\Workflow\Service;

use Platform\Workflow\Model\WorkflowParityReport;
use Platform\Workflow\Model\WorkflowParityStatus;
use Platform\Workflow\Model\WorkflowRunSnapshot;
use Platform\Workflow\Model\WorkflowStepSnapshot;

/**
 * Pure old→new shadow projection comparison. No DB writes, side effects or
 * automatic promotion. Missing attestation never becomes a green match.
 */
final readonly class WorkflowShadowParityComparator
{
    /**
     * @param array<string,mixed> $legacy FederationGoalStore::run() projection
     * @param list<WorkflowStepSnapshot> $steps canonical technical step projection
     * @param array<string,string> $trustedReceiptRefs step_id => independently attested receipt
     */
    public function federation(
        array $legacy,
        ?WorkflowRunSnapshot $target,
        array $steps,
        ?string $verifiedPlanDigest = null,
        array $trustedReceiptRefs = [],
    ): WorkflowParityReport {
        $id = (string) ($legacy['run_id'] ?? '');
        if ($target === null) {
            return new WorkflowParityReport('federation', $id, WorkflowParityStatus::NO_TARGET, ['canonical_run_not_created']);
        }
        $drift = [];
        $missing = [];
        if (($legacy['organization_id'] ?? null) !== $target->identity->organizationId
            || $id !== $target->identity->runId) $drift[] = 'run_identity_mismatch';
        $states = ['pending' => 'created', 'running' => 'running', 'waiting' => 'waiting',
            'ambiguous' => 'ambiguous', 'completed' => 'completed', 'failed' => 'failed',
            'cancelled' => 'cancelled'];
        $legacyState = $states[$legacy['state'] ?? ''] ?? null;
        if ($legacyState === null || $legacyState !== $target->status->value) $drift[] = 'run_state_mismatch';
        if (($legacy['plan_id'] ?? null) !== $target->sourcePlanId) $drift[] = 'plan_link_mismatch';
        if ((int) ($legacy['revision'] ?? -1) !== $target->revision) $drift[] = 'run_revision_mismatch';
        if ($verifiedPlanDigest === null) {
            $missing[] = 'approved_plan_digest_not_attested';
        } elseif ($verifiedPlanDigest !== $target->sourcePlanDigest) {
            $drift[] = 'approved_plan_digest_mismatch';
        }

        $source = $legacy['steps'] ?? null;
        if (!is_array($source) || !array_is_list($source)) {
            $drift[] = 'invalid_legacy_step_projection';
            $source = [];
        }
        $byId = [];
        foreach ($steps as $step) {
            if (!$step instanceof WorkflowStepSnapshot || isset($byId[$step->stepId])) {
                $drift[] = 'invalid_canonical_step_projection';
                continue;
            }
            $byId[$step->stepId] = $step;
        }
        $seen = [];
        foreach ($source as $row) {
            if (!is_array($row) || !is_string($row['step_id'] ?? null)
                || isset($seen[$row['step_id']])) {
                $drift[] = 'invalid_legacy_step_identity';
                continue;
            }
            $stepId = $row['step_id'];
            $seen[$stepId] = true;
            $step = $byId[$stepId] ?? null;
            if ($step === null) {
                $drift[] = 'missing_canonical_step:' . $stepId;
                continue;
            }
            if (($row['capability_id'] ?? null) !== $step->capabilityId
                || ($row['capability_version'] ?? null) !== $step->capabilityVersion
                || ($row['side_effect_level'] ?? null) !== $step->sideEffectLevel
                || ($row['state'] ?? null) !== $step->state
                || (int) ($row['attempts'] ?? -1) !== $step->attempts
                || ($row['idempotency_key'] ?? null) !== $step->idempotencyKey
                || ($row['result_reference'] ?? null) !== $step->resultReference) {
                $drift[] = 'step_execution_mismatch:' . $stepId;
            }
            if ($step->state === 'completed') {
                if (!is_string($step->resultReference) || $step->resultReference === ''
                    || ($trustedReceiptRefs[$stepId] ?? null) !== $step->resultReference) {
                    $missing[] = 'completed_step_receipt_not_attested:' . $stepId;
                }
            }
        }
        foreach ($byId as $stepId => $_step) {
            if (!isset($seen[$stepId])) $drift[] = 'unexpected_canonical_step:' . $stepId;
        }
        if (count($source) !== count($steps)) $drift[] = 'step_count_mismatch';
        return new WorkflowParityReport('federation', $id,
            $drift !== [] ? WorkflowParityStatus::DRIFT
                : ($missing !== [] ? WorkflowParityStatus::PARTIAL : WorkflowParityStatus::MATCH),
            array_values(array_unique([...$drift, ...$missing])));
    }

    /**
     * Engineering's currentState/stage and its transition/AgentRun history
     * deliberately remain DOMAIN-owned. Technical status parity is partial
     * until an explicit migration maps those records.
     *
     * @param array<string,mixed> $legacy DoctrineEngineeringWorkflowStore::view()
     */
    public function engineering(array $legacy, ?WorkflowRunSnapshot $target, ?string $linkedLegacyId, ?string $verifiedOrganizationId): WorkflowParityReport
    {
        $id = (string) ($legacy['id'] ?? '');
        if ($target === null) {
            return new WorkflowParityReport('engineering', $id, WorkflowParityStatus::NO_TARGET,
                ['canonical_run_not_created']);
        }
        $drift = [];
        if ($verifiedOrganizationId === null || $verifiedOrganizationId !== $target->identity->organizationId) {
            $drift[] = 'engineering_tenant_evidence_missing_or_mismatched';
        }
        if (!is_string($legacy['feature_id'] ?? null) || $legacy['feature_id'] === ''
            || !is_string($legacy['state'] ?? null) || $legacy['state'] === ''
            || (int) ($legacy['version'] ?? 0) < 1) {
            $drift[] = 'engineering_business_stage_evidence_missing';
        }
        if ($linkedLegacyId === null || $linkedLegacyId !== $id) {
            $drift[] = 'missing_or_incorrect_engineering_legacy_link';
        }
        $mapped = ['RUNNING' => 'running', 'WAITING' => 'waiting', 'COMPLETED' => 'completed',
            'FAILED' => 'failed', 'CANCELLED' => 'cancelled', 'BLOCKED' => 'waiting'];
        $sourceStatus = $legacy['status'] ?? null;
        if (!is_string($sourceStatus) || !isset($mapped[$sourceStatus])
            || $mapped[$sourceStatus] !== $target->status->value) {
            $drift[] = 'engineering_technical_status_mismatch';
        }
        if (($legacy['trace_id'] ?? null) !== $target->correlationId) {
            $drift[] = 'engineering_trace_mismatch';
        }
        // Even with matching current status the independent domain stage,
        // reviews, QA, human gates and AgentRun receipts are not 1:1 Steps.
        return new WorkflowParityReport('engineering', $id,
            $drift !== [] ? WorkflowParityStatus::DRIFT : WorkflowParityStatus::PARTIAL,
            [...$drift, 'engineering_stages_transitions_agent_runs_not_yet_migrated']);
    }
}
