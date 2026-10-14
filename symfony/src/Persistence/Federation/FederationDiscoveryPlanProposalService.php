<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DomainException;
use Domains\Growth\Application\Contract\GrowthMarketDiscoveryBoundary;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\FederationDiscoveryPlanFactory;

/**
 * Explicit manager-only entry to the existing Federation approval workflow.
 * Prepares a REAL tenant Growth Universe reference, never a synthetic Run.
 */
final readonly class FederationDiscoveryPlanProposalService
{
    public function __construct(
        private FederationGoalStore $goals,
        private FederationCapabilityBindingResolver $bindings,
        private GrowthMarketDiscoveryBoundary $market,
        private FederationDiscoveryPlanFactory $factory,
    ) {}

    /** @return array<string,mixed> */
    public function propose(
        TenantContext $actor,
        string $goalId,
        string $universeId,
        int $slot,
        int $limit,
    ): array {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Golden 50 Discovery Plan requires tenant manager authorization.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D', $goalId)) {
            throw new DomainException('Invalid Golden 50 Goal identifier.');
        }
        foreach (['federation', 'growth', 'sales', 'documents'] as $module) {
            if (!$this->bindings->isTenantModuleEnabled($actor, $module)) {
                throw new DomainException('Golden 50 requires all four tenant modules enabled.');
            }
        }
        $this->bindings->requireExecutable($actor, 'growth.market.discovery');
        $goal = $this->goals->specification($actor, $goalId);
        if ($goal === null) {
            throw new DomainException('Tenant Golden 50 Goal not found.');
        }
        $draft = $this->factory->build($goal, $universeId, $slot, $limit);
        $brief = $this->market->universeBrief($actor->organizationId()->value(), $universeId);
        $universe = $brief['universe'] ?? null;
        if (!is_array($universe)
            || ($universe['organization_id'] ?? null) !== $actor->organizationId()->value()
            || ($universe['universe_id'] ?? null) !== $universeId
            || ($universe['target_domain'] ?? null) !== 'sales'
            || !(bool)($universe['enabled'] ?? false)) {
            throw new DomainException('Discovery requires an enabled, tenant-owned Sales Growth Universe.');
        }

        // This native store validates the actual capability/JSON schema and
        // persists PROPOSED only. Unique Plan ID prevents replay for one slot.
        $this->goals->proposePlan($actor, $draft['plan_id'], $goalId, $draft['steps']);
        return [
            'plan_id' => $draft['plan_id'],
            'state' => 'proposed',
            'source_universe_id' => $universeId,
            'discovery_slot' => $slot,
            'requires_independent_plan_approval' => true,
            'requires_independent_action_approval' => true,
            'business_outcome_verified' => false,
        ];
    }
}
