<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\WorkflowStatus;
use LogicException;
use Platform\Workflow\Contract\WorkflowRecoveryInterface;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Model\WorkflowRecoveryAssessment;
use Platform\Workflow\Model\WorkflowRecoveryDisposition;
use Platform\Workflow\Model\WorkflowRunCommand;
use Throwable;

/**
 * First shared recovery boundary: diagnostic only.
 *
 * It does NOT resume, reclaim, change status, extend a lease, or replay an
 * external side effect. A future mutating reconciler needs canonical receipts
 * and an explicit human-approved compare-and-swap command.
 */
final readonly class DoctrineWorkflowRecoveryInspector implements WorkflowRecoveryInterface
{
    public function __construct(
        private Connection $db,
        private WorkflowReceiptVerifierInterface $receipts,
        private ?WorkflowNonActionReceiptEvidenceInterface $nonActions = null,
    ) {}

    public function inspect(WorkflowRunCommand $command): WorkflowRecoveryAssessment
    {
        $identity = $command->identity;
        $actor = $command->actor;
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Workflow recovery inspection requires a tenant manager.');
        }
        $keys = ['org' => $identity->organizationId, 'run' => $identity->runId];
        $run = $this->db->fetchAssociative(
            'SELECT state, revision, input_digest, definition_json, definition_digest FROM cos_workflow_runs
             WHERE organization_id=:org AND run_id=:run',
            $keys,
        );
        if (!$run) throw new DomainException('Workflow recovery Run not found in tenant.');
        if ((int) $run['revision'] !== $command->expectedRevision) {
            throw new LogicException('Stale Workflow recovery inspection revision.');
        }
        $steps = $this->db->fetchAllAssociative(
            'SELECT step_id, state, side_effect_level, result_reference, attempts,
                    CASE WHEN claim_expires_at IS NOT NULL AND claim_expires_at <= NOW(6)
                          THEN 1 ELSE 0 END AS lease_expired
             FROM cos_workflow_steps WHERE organization_id=:org AND run_id=:run
             ORDER BY ordinal_no',
            $keys,
        );
        $assessment = static fn(
            WorkflowRecoveryDisposition $disposition,
            string $reason,
            bool $unknown = false,
        ): WorkflowRecoveryAssessment => new WorkflowRecoveryAssessment($identity, $disposition, $reason, $unknown);

        if ($steps === []) {
            return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'missing_execution_steps');
        }
        $runStatus = WorkflowStatus::tryFrom((string) $run['state']);
        if ($runStatus === null) {
            return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'unknown_run_state');
        }

        $pinned=[];
        if($this->nonActions!==null) {
            try {
                $graph=json_decode((string)$run['definition_json'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($graph) || !array_is_list($graph)
                    || !hash_equals((string)$run['definition_digest'],
                        WorkflowDefinitionFingerprint::digest($graph))) {
                    return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED,'invalid_pinned_recovery_graph');
                }
                foreach($graph as $item) {
                    if(!is_array($item) || !is_string($item['id']??null)
                        || isset($pinned[$item['id']])) {
                        return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED,'invalid_pinned_recovery_steps');
                    }
                    $pinned[$item['id']]=$item;
                }
                if(count($pinned)!==count($steps)) {
                    return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED,'recovery_definition_step_count_mismatch');
                }
            }catch(Throwable) {
                return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED,'invalid_pinned_recovery_graph');
            }
        }

        // Independent receipt verification precedes any "healthy" assessment,
        // even when a stored flag says that the Step has completed.
        foreach ($steps as $step) {
            if ($step['state'] !== 'completed') continue;
            $reference = $step['result_reference'];
            if (!is_string($reference) || $reference === '') {
                return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'completed_step_without_receipt');
            }
            try {
                if ($step['side_effect_level'] === 'none' && $this->nonActions !== null) {
                    $definition=$pinned[$step['step_id']]??null;
                    if(!is_array($definition) || ($definition['side_effect_level']??null)!=='none') {
                        return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED,'completed_step_untrusted_receipt');
                    }
                    $this->nonActions->assertVerified(
                        $identity,(string)$step['step_id'],$reference,
                        (string)$run['input_digest'],$definition,
                    );
                } else {
                    $attestation = $this->receipts->attest(new WorkflowReceiptRequest(
                        $identity, $actor, (string) $step['step_id'],
                        $reference, (string) $run['input_digest'],
                    ));
                    if (!$attestation->verified || $attestation->identity != $identity
                        || $attestation->stepId !== $step['step_id']
                        || $attestation->evidenceReference !== $reference) {
                        return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'completed_step_untrusted_receipt');
                    }
                }
            } catch (Throwable) {
                return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'completed_step_untrusted_receipt');
            }
        }
        foreach ($steps as $step) {
            $state = (string) $step['state'];
            $effect = (string) $step['side_effect_level'];
            if ($state === 'ambiguous' || $state === 'failed') {
                return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'step_requires_manual_reconciliation', $effect === 'external');
            }
            if ($state === 'claimed') {
                $expired = (int) $step['lease_expired'] === 1;
                if ($effect === 'external') {
                    return $assessment(
                        $expired ? WorkflowRecoveryDisposition::MANUAL_REQUIRED : WorkflowRecoveryDisposition::RECONCILE_ONLY,
                        $expired ? 'external_claim_lease_expired' : 'external_claim_outcome_pending',
                        true,
                    );
                }
                // Even a technically read-only step may have produced a checkpoint
                // before the worker crashed; inspection never auto-replays it.
                return $assessment(WorkflowRecoveryDisposition::RECONCILE_ONLY,
                    $expired ? 'read_only_claim_lease_expired' : 'read_only_claim_in_flight');
            }
            if (!in_array($state, ['pending','completed','waiting','cancelled'], true)) {
                return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'unknown_step_state');
            }
        }
        if ($runStatus === WorkflowStatus::AMBIGUOUS) {
            return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'ambiguous_run_requires_reconciliation', true);
        }
        if (in_array($runStatus, [WorkflowStatus::FAILED, WorkflowStatus::CANCELLED], true)) {
            return $assessment(WorkflowRecoveryDisposition::NO_ACTION, 'terminal_without_replay');
        }
        if ($runStatus === WorkflowStatus::COMPLETED) {
            foreach ($steps as $step) {
                if ($step['state'] !== 'completed') {
                    return $assessment(WorkflowRecoveryDisposition::MANUAL_REQUIRED, 'terminal_run_has_incomplete_steps');
                }
            }
            return $assessment(WorkflowRecoveryDisposition::NO_ACTION, 'terminal_receipts_verified');
        }
        if (count(array_filter($steps, static fn(array $step): bool => $step['state'] === 'completed')) === count($steps)) {
            return $assessment(WorkflowRecoveryDisposition::RECONCILE_ONLY, 'ready_for_verified_finalization');
        }
        return $assessment(WorkflowRecoveryDisposition::NO_ACTION, 'no_automatic_recovery_action');
    }

    public function reconcile(WorkflowRunCommand $command): WorkflowRecoveryAssessment
    {
        // Authentication, revision and receipts still checked before rejecting.
        $this->inspect($command);
        throw new DomainException('Mutating Workflow reconciliation is disabled until canonical Action evidence and a human gate are wired.');
    }
}
