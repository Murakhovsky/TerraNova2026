<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Federation\Application\Service\GoalPlanApprovalInvariant;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Throwable;

/**
 * The sole canonical Action handler that can approve a Federation plan.
 * Never starts a Workflow, Tool, Agent, or external side effect.
 *
 * This handler is installed by the opt-in "federation" module, and the
 * canonical ModuleActionExecutionGate blocks tenants without the module.
 */
final readonly class FederationPlanApproveHandler implements ActionHandlerInterface
{
    public const ACTION_TYPE = 'cos.federation.plan.approval';

    public function __construct(
        private Connection $db,
        private FederationPlanApprovalEvidenceReader $evidence,
    ) {}

    public function supports(string $actionType): bool
    {
        return $actionType === self::ACTION_TYPE;
    }

    public function execute(Action $action): ExecutionResult
    {
        try {
            GoalPlanApprovalInvariant::assertAction($action);
        } catch (DomainException $error) {
            return ExecutionResult::failure($error->getMessage());
        }

        try {
            $result = $this->db->transactional(function () use ($action): array {
                $plan = $this->db->fetchAssociative(
                    'SELECT p.goal_id, p.spec_version, p.state, p.plan_json,
                            g.owner_id, g.current_spec_version
                     FROM cos_federation_plans p
                     JOIN cos_federation_goals g
                       ON g.organization_id = p.organization_id AND g.goal_id = p.goal_id
                     WHERE p.organization_id = :org AND p.plan_id = :plan FOR UPDATE',
                    ['org' => $action->organizationId, 'plan' => $action->targetId],
                );
                if (!$plan) {
                    throw new DomainException('Federation plan is not present in this tenant.');
                }
                GoalPlanApprovalInvariant::assertPlan($action, $plan);
                $receipt = $this->evidence->requireForOrganization(
                    $action->organizationId, $action->id, (string) $plan['goal_id'],
                    (string) $action->targetId, (int) $plan['spec_version'],
                    (string) $plan['plan_json'], (string) $plan['owner_id'], 'RUNNING',
                );
                if ($plan['state'] === 'proposed') {
                    $changed = $this->db->executeStatement(
                        'UPDATE cos_federation_plans SET state = :approved
                         WHERE organization_id = :org AND plan_id = :plan AND state = :proposed',
                        [
                            'approved' => 'approved', 'org' => $action->organizationId,
                            'plan' => $action->targetId, 'proposed' => 'proposed',
                        ],
                    );
                    if ($changed !== 1) {
                        throw new DomainException('Federation plan approval lost an optimistic state race.');
                    }
                }
                return [
                    'plan_id' => (string) $action->targetId,
                    'approval_id' => $receipt['approval_id'],
                    'goal_id' => (string) $plan['goal_id'],
                    'state' => 'approved',
                ];
            });
        } catch (Throwable $error) {
            return ExecutionResult::failure('Federation plan activation denied: ' . $error->getMessage());
        }
        return ExecutionResult::success($result);
    }
}
