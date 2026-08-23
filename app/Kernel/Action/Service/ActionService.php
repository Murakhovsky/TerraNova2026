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
use Domains\Sales\Event\ActionExecuted;
use Infrastructure\Database\Transaction\TransactionManager;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;

final readonly class ActionService
{
    public function __construct(
        private ActionRepositoryInterface $actions,
        private ActionExecutor $executor,
        private ?EventStoreInterface $events = null,
        private ?AuditRepositoryInterface $audit = null,
        private ?TransactionManager $transactions = null,
    ) {}

    public function propose(
        string $organizationId,
        ActionProposal $proposal,
        string $correlationId,
        ?ActionStatus $initialStatus = null,
    ): Action
    {
        $status = $initialStatus ?? match ($proposal->executionMode) {
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

    public function requestApproval(string $organizationId, string $actionId): void
    {
        if (!$this->actions->transition($organizationId, $actionId, ActionStatus::Proposed, ActionStatus::PendingApproval)) {
            throw new DomainException('Action cannot enter approval from its current status.');
        }
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

        return $this->executeClaimed($action, $workerId);
    }

    public function execute(string $organizationId, string $actionId, string $workerId): ?Action
    {
        $action = $this->actions->claim($organizationId, $actionId, $workerId);
        if ($action === null) return null;

        return $this->executeClaimed($action, $workerId);
    }

    private function executeClaimed(Action $action, string $workerId): Action
    {

        try {
            $result = $this->executor->execute($action);
        } catch (Throwable $exception) {
            $result = ExecutionResult::failure($exception->getMessage());
            if ($action->status === ActionStatus::Running) {
                $action->transitionTo(ActionStatus::Failed);
            }
        }
        $finish = function () use ($action, $result, $workerId): void {
            $this->actions->finish($action, $result);
            $event = ActionExecuted::create($action, $result, $workerId);
            $this->events?->append($event);
            $this->audit?->append(new AuditEntry(
                bin2hex(random_bytes(16)), $action->organizationId, 'ACTION_EXECUTION', 'WORKER', $workerId,
                $action->targetType ?? 'action', $action->targetId ?? $action->id,
                $result->error,
                [
                    'action' => $action->type,
                    'input_references' => ['action_id' => $action->id, 'source_type' => $action->sourceType, 'source_id' => $action->sourceId],
                    'changes' => $result->data['changes'] ?? [],
                    'result' => ['status' => $result->status(), 'output' => $result->data, 'error' => $result->error, 'metrics' => $result->metrics],
                    'metadata' => ['risk_level' => $action->riskLevel, 'execution_mode' => $action->executionMode],
                ],
                $action->correlationId ?: $action->id,
                new DateTimeImmutable(),
            ));
        };
        if ($this->transactions !== null) {
            $this->transactions->transactional($finish);
        } else {
            $finish();
        }
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
