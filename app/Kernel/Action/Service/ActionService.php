<?php
declare(strict_types=1);

namespace Kernel\Action\Service;

use DateTimeImmutable;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionProposal;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionRepositoryInterface;
use Kernel\Action\ExecutionResult;
use Throwable;

final readonly class ActionService
{
    public function __construct(
        private ActionRepositoryInterface $actions,
        private ActionExecutor $executor,
    ) {}

    public function propose(string $organizationId, ActionProposal $proposal, string $correlationId): Action
    {
        $status = match ($proposal->executionMode) {
            'AUTO' => ActionStatus::Queued,
            'APPROVAL_REQUIRED' => ActionStatus::PendingApproval,
            default => ActionStatus::Proposed,
        };

        return $this->actions->save(new Action(
            bin2hex(random_bytes(16)),
            $organizationId,
            $proposal->type,
            $proposal->targetType,
            $proposal->targetId,
            $proposal->parameters,
            $proposal->sourceType,
            $proposal->sourceId,
            $proposal->executionMode,
            $proposal->riskLevel,
            $proposal->idempotencyKey,
            new DateTimeImmutable(),
            $status,
            $correlationId,
        ));
    }

    public function queue(string $organizationId, string $actionId): void
    {
        foreach ([ActionStatus::Proposed, ActionStatus::PendingApproval, ActionStatus::Failed] as $from) {
            if ($this->actions->transition($organizationId, $actionId, $from, ActionStatus::Queued)) return;
        }
        throw new DomainException('Action cannot be queued from its current status.');
    }

    public function reject(string $organizationId, string $actionId): void
    {
        foreach ([ActionStatus::Proposed, ActionStatus::PendingApproval] as $from) {
            if ($this->actions->transition($organizationId, $actionId, $from, ActionStatus::Rejected)) return;
        }
        throw new DomainException('Action cannot be rejected from its current status.');
    }

    public function executeNext(string $workerId): ?Action
    {
        $action = $this->actions->claimNext($workerId);
        if ($action === null) return null;

        try {
            $result = $this->executor->execute($action);
        } catch (Throwable $exception) {
            $result = ExecutionResult::failure($exception->getMessage());
            if ($action->status === ActionStatus::Running) {
                $action->transitionTo(ActionStatus::Failed);
            }
        }
        $this->actions->finish($action, $result);
        return $action;
    }

    public function find(string $organizationId, string $actionId): ?Action
    {
        return $this->actions->find($organizationId, $actionId);
    }

    public function recoverStale(int $olderThanSeconds = 300): int
    {
        return $this->actions->requeueStale($olderThanSeconds);
    }
}
