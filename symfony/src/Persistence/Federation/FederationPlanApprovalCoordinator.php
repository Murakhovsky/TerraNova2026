<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\ActionStatus;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalPlanApprovalRequestFactory;

/**
 * Strictly gated adapter into the canonical ActionPolicyService.
 *
 * No owning Action module / handler is installed in this phase. In that state
 * requestApproval ALWAYS refuses without writing to Action/Approval tables.
 * Future activation must install a handler that re-checks the canonical
 * approval evidence before mutating a plan, including when Policy returns AUTO.
 */
final readonly class FederationPlanApprovalCoordinator
{
    private const ACTION_TYPE = 'cos.federation.plan.approval';

    public function __construct(
        private Connection $connection,
        private FederationGoalStore $goals,
        private DomainModuleRegistry $domains,
        private ActiveModuleResolver $modules,
        private GoalPlanApprovalRequestFactory $factory,
        private ActionPolicyService $policies,
    ) {}

    /** @return array{action_id:string,status:string} */
    public function requestApproval(
        TenantContext $actor,
        string $planId,
        string $correlationId,
    ): array {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Manager authorization required for Goal plan approval request.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $planId)) {
            throw new DomainException('Invalid Goal plan identifier.');
        }
        // Fail before any persistence or Policy submission if canonical action ownership is absent.
        $owner = $this->domains->ownerOfAction(self::ACTION_TYPE);
        $handlers = $this->domains->actionHandlerMap();
        if ($owner === null || !isset($handlers[self::ACTION_TYPE])
            || !$handlers[self::ACTION_TYPE]->supports(self::ACTION_TYPE)) {
            throw new DomainException('Federation plan approval handler is not registered. Feature is disabled.');
        }

        $org = $actor->organizationId()->value();
        if (!$this->modules->isEnabled($org, $owner)) {
            throw new DomainException('Federation approval Action owner module is not enabled for tenant.');
        }
        $row = $this->connection->fetchAssociative(
            'SELECT goal_id, spec_version, state, plan_json FROM cos_federation_plans
             WHERE organization_id = :org AND plan_id = :plan',
            ['org' => $org, 'plan' => $planId],
        );
        if (!$row || $row['state'] !== 'proposed') {
            throw new DomainException('Only a tenant-owned proposed Goal plan can request approval.');
        }
        $goal = $this->goals->specification($actor, (string) $row['goal_id']);
        if ($goal === null || $goal->version !== (int) $row['spec_version']) {
            throw new DomainException('Goal plan specification has changed.');
        }

        $proposal = $this->factory->create(
            $goal, $planId, (string) $row['plan_json'], $actor->userId()->value(),
        );
        $action = $this->policies->submit($org, $proposal, $correlationId);
        if ($action->status !== ActionStatus::PendingApproval) {
            // Never consider AUTO, PROPOSED or REJECTED sufficient to approve the plan.
            // The eventual Action handler must enforce the same human-only invariant.
            throw new DomainException('Federation plan requires a human Approval decision from Policy.');
        }
        return ['action_id' => $action->id, 'status' => $action->status->value];
    }
}
