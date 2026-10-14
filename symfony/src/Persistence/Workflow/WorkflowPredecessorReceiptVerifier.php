<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use DomainException;
use Kernel\Action\Action;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Last-moment mandatory verification of ALL completed predecessors, not merely
 * flags for the immediate parent. Shared pure scheduler already enforces DAG.
 *
 * All evidence is re-read from canonical persistence before Domain mutation.
 * This verifier is read-only and never submits, retries or promotes a Step.
 */
final readonly class WorkflowPredecessorReceiptVerifier
{
    public function __construct(
        private DoctrineWorkflowActionReceiptVerifier $actions,
        private WorkflowNonActionReceiptEvidenceInterface $nonActions,
    ) {}

    /**
     * @param list<array<string,mixed>> $pinnedGraph
     * @param list<string> $completedStepIds From validated WorkflowStepScheduler
     * @param list<array<string,mixed>> $persistedRows
     */
    public function assertVerified(
        Action $currentAction,
        WorkflowRunIdentity $identity,
        string $inputDigest,
        array $pinnedGraph,
        array $completedStepIds,
        array $persistedRows,
    ): void {
        if (!preg_match('/^[a-f0-9]{64}$/', $inputDigest)
            || $identity->organizationId !== $currentAction->organizationId) {
            throw new DomainException('Workflow predecessor identity/input digest invalid.');
        }
        $definitions = [];
        foreach ($pinnedGraph as $step) {
            if (!is_array($step) || !is_string($step['id'] ?? null)
                || isset($definitions[$step['id']])) {
                throw new DomainException('Invalid immutable predecessor graph.');
            }
            $definitions[$step['id']] = $step;
        }
        $byId = [];
        foreach ($persistedRows as $step) {
            $id = $step['step_id'] ?? null;
            if (!is_string($id) || isset($byId[$id])) {
                throw new DomainException('Invalid or repeated stored predecessor.');
            }
            $byId[$id] = $step;
        }
        if (count($definitions) !== count($byId)) {
            throw new DomainException('Persisted Workflow steps differ from immutable Plan.');
        }
        $seen = [];
        foreach ($completedStepIds as $stepId) {
            if (isset($seen[$stepId]) || !isset($definitions[$stepId], $byId[$stepId])) {
                throw new DomainException('Missing or duplicated completed predecessor.');
            }
            $seen[$stepId] = true;
            $pinned = $definitions[$stepId];
            $row = $byId[$stepId];
            $expectedKey = hash('sha256', $identity->organizationId . "\0" . $identity->runId . "\0" . $stepId);
            $receipt = $row['result_reference'] ?? null;
            if (($row['state'] ?? null) !== 'completed'
                || ($row['capability_id'] ?? null) !== ($pinned['capability_id'] ?? null)
                || ($row['capability_version'] ?? null) !== ($pinned['capability_version'] ?? null)
                || ($row['side_effect_level'] ?? null) !== ($pinned['side_effect_level'] ?? null)
                || ($row['idempotency_key'] ?? null) !== $expectedKey
                || (int) ($row['attempts'] ?? 0) !== 1
                || (int) ($row['revision'] ?? 0) < 2
                || !is_string($receipt) || $receipt === '') {
                throw new DomainException('Completed Workflow Step has no pinned, versioned, one-attempt receipt.');
            }
            if ($row['side_effect_level'] === 'external') {
                if (!preg_match('/^action:[a-f0-9]{32}$/', $receipt)
                    || !is_string($pinned['execution_binding'] ?? null)
                    || !str_starts_with($pinned['execution_binding'], 'action:')) {
                    throw new DomainException('Completed external predecessor has no canonical Action binding.');
                }
                // Canonical Action, exact idempotency key, target/inputs,
                // unique COMPLETED attempt, latest Policy, human Approval,
                // immutable Plan all verified by the same Action receipt service.
                $proof = $this->actions->attestForWorker(
                    $currentAction, $identity, $stepId, $receipt, $inputDigest,
                );
                if (!$proof->verified || $proof->provider !== 'canonical-action'
                    || $proof->identity != $identity || $proof->stepId !== $stepId
                    || $proof->evidenceReference !== $receipt
                    || $proof->correlationId !== $currentAction->correlationId) {
                    throw new DomainException('Canonical Action predecessor did not attest exact provenance.');
                }
            } elseif ($row['side_effect_level'] === 'none') {
                $this->nonActions->assertVerified(
                    $identity, $stepId, $receipt, $inputDigest, $pinned,
                );
            } else {
                throw new DomainException('Unsupported predecessor effect cannot be attested.');
            }
        }
        foreach ($byId as $stepId => $row) {
            if ($row['state'] === 'completed' && !isset($seen[$stepId])) {
                throw new DomainException('Completed predecessor was omitted from worker attestation.');
            }
        }
    }
}
