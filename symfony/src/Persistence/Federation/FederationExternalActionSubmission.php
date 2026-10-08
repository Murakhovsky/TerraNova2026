<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\ActionStatus;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Policy\Contract\PolicyRepositoryInterface;
use Kernel\Policy\PolicyDecision;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Policy\Service\PolicyContextBuilder;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Tenant\Model\TenantContext;

/**
 * Synchronous admission of a single approved Federation external step into
 * canonical ActionPolicyService, never direct Domain execution.
 * The last-moment Action worker gate independently re-verifies human Approval.
 */
final readonly class FederationExternalActionSubmission
{
    public function __construct(
        private Connection $db,
        private FederationApprovedActionIntentFactory $intents,
        private FederationGoalStore $goals,
        private PolicyRepositoryInterface $policies,
        private PolicyEngine $policyEngine,
        private PolicyContextBuilder $contexts,
        private ActionPolicyService $actions,
        private DomainModuleRegistry $domains,
        private ActiveModuleResolver $modules,
    ) {}

    /** @return array{action_id:string,status:string,step_id:string,run_id:string} */
    public function submit(
        TenantContext $actor,
        string $runId,
        string $stepId,
        string $planApprovalActionId,
    ): array {
        // All goal, plan, step, tenant and immutable schema checks happen here.
        $proposal = $this->intents->create($actor, $runId, $stepId, $planApprovalActionId);
        $org = $actor->organizationId()->value();
        $owner = $this->domains->ownerOfAction($proposal->type);
        if ($owner === null || !$this->modules->isEnabled($org, $owner)) {
            throw new DomainException('Domain owner module disabled before Federation Action submission.');
        }

        // Fail closed before claiming the step if the current tenant Policy would
        // permit AUTO, DENIED or HUMAN_ONLY. The worker checks its own Approval
        // again, closing a Policy-change race after submission.
        $policy = $this->policyEngine->evaluate(
            $proposal->type,
            $this->contexts->build($org, $proposal),
            $this->policies->activeFor($org, $proposal->type),
        );
        if ($policy->decision !== PolicyDecision::ApprovalRequired) {
            throw new DomainException('Federated external Action requires explicit APPROVAL_REQUIRED Policy.');
        }

        // Claims exactly once. If a crash occurs afterward, reuse the same
        // persisted idempotency reference for manual reconciliation, NEVER
        // automatically re-submit or re-execute.
        $claimedKey = $this->goals->claimStep($actor, $runId, $stepId);
        if (!hash_equals('fed:' . $claimedKey, $proposal->idempotencyKey)) {
            throw new DomainException('Federation Step idempotency drift.');
        }
        $correlation = 'federation:' . $runId . ':' . $stepId;
        $action = $this->actions->submit($org, $proposal, $correlation);
        if ($action->status !== ActionStatus::PendingApproval) {
            // Canonical worker admission still rejects AUTO / unapproved
            // execution. Treat this as a manual-reconciliation case.
            throw new DomainException('Federation Action did not enter human approval state.');
        }
        $changed = $this->db->executeStatement(
            'UPDATE cos_federation_steps
             SET result_reference = :action_reference, checkpoint_json = :checkpoint, updated_at = :now
             WHERE organization_id = :org AND run_id = :run AND step_id = :step
               AND state = :claimed AND idempotency_key = :key AND result_reference IS NULL',
            [
                'action_reference' => 'action:' . $action->id,
                'checkpoint' => json_encode([
                    'action_id' => $action->id,
                    'status' => 'awaiting_independent_human_approval',
                ], JSON_THROW_ON_ERROR),
                'now' => gmdate('Y-m-d H:i:s.u'), 'org' => $org,
                'run' => $runId, 'step' => $stepId, 'claimed' => 'claimed',
                'key' => $claimedKey,
            ],
        );
        if ($changed !== 1) {
            throw new DomainException('Action submitted but Step checkpoint needs manual reconciliation.');
        }
        return [
            'action_id' => $action->id,
            'status' => $action->status->value,
            'step_id' => $stepId,
            'run_id' => $runId,
        ];
    }
}
