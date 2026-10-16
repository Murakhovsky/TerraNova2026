<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Pure Golden 50 parent discovery Plan proposal. One explicit, immutable
 * native Growth Action per human-selected universe and collection slot.
 * Does not approve, run or invent candidate identities.
 */
final readonly class FederationDiscoveryPlanFactory
{
    public const MAX_SLOTS = 10;

    /** @return array{plan_id:string,steps:list<array<string,mixed>>,slot:int} */
    public function build(GoalSpecification $goal, string $universeId, int $slot, int $limit): array
    {
        if (count($goal->criteria) !== 1
            || ($goal->criteria[0]['id'] ?? null) !== FederationGolden50GoalProfile::METRIC
            || ($goal->criteria[0]['operator'] ?? null) !== 'at_least'
            || ($goal->criteria[0]['expected'] ?? null) !== FederationGolden50GoalProfile::TARGET
            || !in_array('growth.market.discovery', $goal->allowedCapabilities, true)) {
            throw new DomainException('Discovery Plans require the exact Golden 50 Goal contract.');
        }
        if (!ctype_digit($goal->ownerId) || (int) $goal->ownerId < 1) {
            throw new DomainException('Native Growth discovery requires a numeric Goal author.');
        }
        if ($universeId === '' || mb_strlen($universeId) > 80
            || $slot < 1 || $slot > self::MAX_SLOTS
            || $limit < 1 || $limit > 200) {
            throw new DomainException('Discovery universe, slot or bounded batch limit is invalid.');
        }
        $planId = 'plan-' . substr(hash('sha256', implode("\0", [
            'golden50-discovery-v1', $goal->organizationId, $goal->goalId,
            $universeId, (string) $slot,
        ])), 0, 24);

        return [
            'plan_id' => $planId,
            'slot' => $slot,
            'steps' => [[
                'id' => 'discovery',
                'depends_on' => [],
                'capability_id' => 'growth.market.discovery',
                'capability_version' => '1.0.0',
                'input' => [
                    'target_type' => 'growth_market_universe',
                    'target_id' => $universeId,
                    'parameters' => ['limit' => $limit],
                ],
            ]],
        ];
    }
}
