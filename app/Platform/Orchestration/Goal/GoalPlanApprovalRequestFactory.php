<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;
use Kernel\Action\ActionProposal;

/**
 * Constructs the canonical Policy/Approval request but never submits it.
 * Wiring into ActionPolicyService requires a registered, tenant-gated
 * cos.federation.plan.approval Action handler. No bypass or implicit dispatch.
 */
final readonly class GoalPlanApprovalRequestFactory
{
    public function create(
        GoalSpecification $goal,
        string $planId,
        string $storedPlanJson,
        string $actorId,
    ): ActionProposal {
        if ($actorId !== $goal->ownerId || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $planId)) {
            throw new DomainException('Plan approval requires its authenticated Goal owner and a valid plan id.');
        }
        $decoded = json_decode($storedPlanJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded['steps'] ?? null) || $decoded['steps'] === []) {
            throw new DomainException('Plan approval request requires a nonempty, canonical plan snapshot.');
        }
        $hash = hash('sha256', $storedPlanJson);
        return new ActionProposal(
            type: 'cos.federation.plan.approval',
            targetType: 'cos_federation_plan',
            targetId: $planId,
            parameters: [
                'goal_id' => $goal->goalId,
                'plan_id' => $planId,
                'specification_version' => $goal->version,
                'plan_hash' => $hash,
            ],
            sourceType: 'USER',
            sourceId: $actorId,
            executionMode: 'APPROVAL_REQUIRED',
            riskLevel: 'HIGH',
            idempotencyKey: 'cos:federation:approval:' . hash(
                'sha256', $goal->organizationId . "\0" . $goal->goalId . "\0" . $planId . "\0"
                . $goal->version . "\0" . $hash,
            ),
            policyContext: [
                'actor' => ['user_id' => $actorId, 'organization_id' => $goal->organizationId],
                'federation' => [
                    'goal_id' => $goal->goalId,
                    'plan_id' => $planId,
                    'specification_version' => $goal->version,
                    'plan_hash' => $hash,
                ],
            ],
        );
    }
}
