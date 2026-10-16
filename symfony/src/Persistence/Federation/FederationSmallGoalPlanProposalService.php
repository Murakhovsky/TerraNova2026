<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\FederationSmallTaskPlanFactory;

/**
 * Transactionally permits a small, typed plan; never approves or dispatches it.
 * Both repeated form submissions and concurrent plan versions are fenced.
 */
final readonly class FederationSmallGoalPlanProposalService
{
    public function __construct(
        private Connection $db,
        private FederationGoalStore $goals,
        private FederationCapabilityBindingResolver $bindings,
        private FederationSmallTaskPlanFactory $factory,
    ) {}

    /** @param list<array{deal_id:string,title:string,due_in_minutes:int}> $tasks
     *  @return array{plan_id:string,state:string,operations:int}
     */
    public function propose(TenantContext $actor, string $goalId, array $tasks): array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Only a tenant manager can build a Goal plan.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D', $goalId)) {
            throw new DomainException('Invalid Goal identifier.');
        }
        // Verify the real Domain Action and tenant module before any state change.
        $this->bindings->requireExecutable($actor, FederationSmallTaskPlanFactory::CAPABILITY);
        $org = $actor->organizationId()->value();
        return $this->db->transactional(function () use ($actor, $goalId, $tasks, $org): array {
            $locked = $this->db->fetchOne(
                'SELECT goal_id FROM cos_federation_goals
                 WHERE organization_id=:org AND goal_id=:goal FOR UPDATE',
                ['org' => $org, 'goal' => $goalId],
            );
            if ($locked === false) {
                throw new DomainException('Goal was not found in the current organization.');
            }
            $goal = $this->goals->enableSalesTaskPlanning($actor, $goalId);
            $draft = $this->factory->build($goal, $tasks);
            $existing = $this->db->fetchAssociative(
                'SELECT state FROM cos_federation_plans
                 WHERE organization_id=:org AND plan_id=:plan',
                ['org' => $org, 'plan' => $draft['plan_id']],
            );
            if ($existing) {
                return ['plan_id' => $draft['plan_id'],
                    'state' => (string) $existing['state'], 'operations' => count($draft['steps'])];
            }
            // A goal row is locked, so two distinct requests cannot allocate
            // the same UNIQUE(organization,goal,plan_version) sequence.
            $version = 1 + (int) $this->db->fetchOne(
                'SELECT COALESCE(MAX(plan_version),0) FROM cos_federation_plans
                 WHERE organization_id=:org AND goal_id=:goal',
                ['org' => $org, 'goal' => $goalId],
            );
            $this->goals->proposePlan(
                $actor, $draft['plan_id'], $goalId, $draft['steps'], $version,
            );
            return ['plan_id' => $draft['plan_id'], 'state' => 'proposed',
                'operations' => count($draft['steps'])];
        });
    }
}
