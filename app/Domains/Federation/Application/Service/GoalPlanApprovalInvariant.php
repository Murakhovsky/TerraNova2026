<?php
declare(strict_types=1);

namespace Domains\Federation\Application\Service;

use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;

/**
 * Federation domain's application boundary: approval and immutable plan
 * invariants, independent of Symfony, Doctrine and transport concerns.
 */
final class GoalPlanApprovalInvariant
{
    public static function assertAction(Action $action): void
    {
        if ($action->type !== 'cos.federation.plan.approval'
            || $action->status !== ActionStatus::Running
            || $action->sourceType !== 'USER'
            || $action->sourceId === ''
            || $action->executionMode !== 'APPROVAL_REQUIRED'
            || $action->targetType !== 'cos_federation_plan'
            || !is_string($action->targetId)
            || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $action->targetId)) {
            throw new DomainException('Federation approval requires a trusted, running, human-approved Action.');
        }
    }

    /** @param array<string,mixed> $plan */
    public static function assertPlan(Action $action, array $plan): void
    {
        if (!in_array($plan['state'] ?? null, ['proposed', 'approved'], true)) {
            throw new DomainException('Federation plan cannot be approved from its current state.');
        }
        if ((int) ($plan['spec_version'] ?? -1) !== (int) ($plan['current_spec_version'] ?? -2)
            || ($plan['owner_id'] ?? null) !== $action->sourceId) {
            throw new DomainException('Goal owner or specification changed after plan proposal.');
        }
    }
}
