<?php
declare(strict_types=1);

namespace Kernel\Action;

use DateTimeImmutable;
use DomainException;

final class Action
{
    public ?DateTimeImmutable $executedAt = null;

    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $type,
        public readonly ?string $targetType,
        public readonly ?string $targetId,
        public readonly array $parameters,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $executionMode,
        public readonly string $riskLevel,
        public readonly ?string $idempotencyKey,
        public readonly DateTimeImmutable $createdAt,
        public ActionStatus $status = ActionStatus::Proposed,
        public readonly string $correlationId = '',
    ) {
    }

    public function transitionTo(ActionStatus $next): void
    {
        $allowed = match ($this->status) {
            ActionStatus::Proposed => [ActionStatus::PendingApproval, ActionStatus::Queued, ActionStatus::Rejected],
            ActionStatus::PendingApproval => [ActionStatus::Queued, ActionStatus::Rejected],
            ActionStatus::Queued => [ActionStatus::Running],
            ActionStatus::Running => [ActionStatus::Completed, ActionStatus::Failed],
            ActionStatus::Failed => [ActionStatus::Queued],
            ActionStatus::Completed, ActionStatus::Rejected => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new DomainException(sprintf('Invalid action transition from %s to %s.', $this->status->value, $next->value));
        }

        $this->status = $next;
        if ($next === ActionStatus::Completed) {
            $this->executedAt = new DateTimeImmutable();
        }
    }
}
