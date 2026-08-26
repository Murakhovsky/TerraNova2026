<?php
declare(strict_types=1);

use Kernel\Action\Action;
use Kernel\Action\ActionProposal;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\Contract\ActionRepositoryInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;
use Kernel\Action\Event\ActionExecutionFinished;
use Kernel\Approval\Approval;
use Kernel\Approval\ApprovalStatus;
use Kernel\Approval\Contract\ApprovalRepositoryInterface;
use Kernel\Approval\Service\ApprovalService;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Rule\Service\ConditionEvaluator;
use Infrastructure\Database\Transaction\TransactionManager;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Job;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Kernel\\' => '/app/Kernel/', 'Infrastructure\\' => '/app/Infrastructure/', 'Domains\\' => '/app/Domains/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});

$repository = new class implements ActionRepositoryInterface {
    /** @var array<string, Action> */ public array $items = [];
    public function save(Action $action): Action {
        foreach ($this->items as $item) {
            if ($item->organizationId === $action->organizationId && $item->idempotencyKey === $action->idempotencyKey) return $item;
        }
        return $this->items[$action->id] = $action;
    }
    public function find(string $organizationId, string $id): ?Action { return $this->items[$id] ?? null; }
    public function existsByIdempotencyKey(string $organizationId, string $key): bool {
        foreach ($this->items as $item) if ($item->organizationId === $organizationId && $item->idempotencyKey === $key) return true;
        return false;
    }
    public function transition(string $organizationId, string $id, ActionStatus $from, ActionStatus $to): bool {
        $action = $this->items[$id] ?? null;
        if (!$action || $action->organizationId !== $organizationId || $action->status !== $from) return false;
        $action->transitionTo($to); return true;
    }
    public function claimNext(string $workerId): ?Action {
        foreach ($this->items as $action) {
            if ($action->status === ActionStatus::Queued) { $action->transitionTo(ActionStatus::Running); return $action; }
        }
        return null;
    }
    public function claim(string $organizationId, string $id, string $workerId): ?Action {
        foreach ($this->items as $action) {
            if ($action->organizationId === $organizationId && $action->id === $id && $action->status === ActionStatus::Queued) {
                $action->transitionTo(ActionStatus::Running); return $action;
            }
        }
        return null;
    }
    public function finish(Action $action, ExecutionResult $result): void {}
    public function requeueStale(int $olderThanSeconds): int { return 0; }
};
$executions = 0;
$handler = new class($executions) implements ActionHandlerInterface {
    public function __construct(private int &$executions) {}
    public function supports(string $actionType): bool { return $actionType === 'sales.send_message'; }
    public function execute(Action $action): ExecutionResult { $this->executions++; return ExecutionResult::success(['sent' => true]); }
};
$service = new ActionService($repository, new ActionExecutor([$handler]));
$proposal = new ActionProposal(
    'sales.send_message', 'deal', '184', ['channel' => 'telegram'],
    'AGENT', 'run-1', 'AUTO', 'MEDIUM', 'deal-184-financing-followup',
);
$first = $service->propose('default', $proposal, 'correlation-1');
$duplicate = $service->propose('default', $proposal, 'correlation-1');
if ($first->id !== $duplicate->id || count($repository->items) !== 1) {
    throw new RuntimeException('Idempotency did not collapse duplicate actions.');
}
$completed = $service->executeNext('worker-1');
$nothing = $service->executeNext('worker-2');
if ($completed?->status !== ActionStatus::Completed || $nothing !== null || $executions !== 1) {
    throw new RuntimeException('Action was not executed exactly once.');
}
$resultWithMetrics = ExecutionResult::success(['message_id' => '1'], ['messages_sent' => 1]);
$resultEvent = ActionExecutionFinished::create($completed, $resultWithMetrics, 'worker-1');
if ($resultWithMetrics->status() !== 'SUCCESS'
    || $resultEvent->type !== ActionExecutionFinished::COMPLETED
    || $resultEvent->payload['metrics']['messages_sent'] !== 1
) {
    throw new RuntimeException('Execution Result event is invalid.');
}

echo "Action Engine smoke test passed.\n";

$policyEngine = new PolicyEngine(new ConditionEvaluator());
$context = ['action' => ['parameters' => ['discount_percent' => 5], 'source_type' => 'AGENT']];
$decision = $policyEngine->decide('sales.apply_discount', $context, [
    new ActionPolicy('small', 'default', 'sales.apply_discount', [
        ['field' => 'action.parameters.discount_percent', 'operator' => '<=', 'value' => 3],
    ], PolicyDecision::Auto, 10),
    new ActionPolicy('large', 'default', 'sales.apply_discount', [
        ['field' => 'action.parameters.discount_percent', 'operator' => '>', 'value' => 3],
    ], PolicyDecision::ApprovalRequired, 20),
]);
if ($decision !== PolicyDecision::ApprovalRequired) throw new RuntimeException('Policy decision failed.');

$approvalProposal = new ActionProposal(
    'sales.apply_discount', 'deal', '185', ['discount_percent' => 5],
    'AGENT', 'run-2', 'APPROVAL_REQUIRED', 'HIGH', 'deal-185-discount-5',
);
$approvalAction = $service->propose('default', $approvalProposal, 'correlation-2');
$approvalRepository = new class implements ApprovalRepositoryInterface {
    public ?Approval $approval = null;
    public function createFor(Action $action, string $approverType, string $approverId, string $reason): Approval {
        return $this->approval = new Approval('approval-1', $action->organizationId, $action->id, ApprovalStatus::Pending, $approverType, $approverId, $reason);
    }
    public function findPending(string $organizationId, string $approvalId): ?Approval {
        return $this->approval?->status === ApprovalStatus::Pending ? $this->approval : null;
    }
    public function decide(string $organizationId, string $approvalId, ApprovalStatus $decision, string $userId, ?string $note): bool {
        if ($this->approval?->status !== ApprovalStatus::Pending) return false;
        $this->approval = new Approval($this->approval->id, $this->approval->organizationId, $this->approval->actionId, $decision, $this->approval->approverType, $this->approval->approverId, $this->approval->reason);
        return true;
    }
};
$approval = $approvalRepository->createFor($approvalAction, 'ROLE', 'manager', 'Large discount');
$pdo = new PDO('sqlite::memory:');
$approvalQueue = new class implements JobQueueInterface {
    public array $enqueued = [];
    public function enqueue(string $organizationId, string $type, array $payload, string $correlationId, ?string $idempotencyKey = null, int $maxAttempts = 5, int $timeoutSeconds = 60): string {
        $this->enqueued[] = compact('organizationId', 'type', 'payload', 'correlationId', 'idempotencyKey'); return 'job-approval';
    }
    public function claim(string $workerId): ?Job { return null; }
    public function complete(Job $job): void {}
    public function fail(Job $job, string $error): void {}
    public function recoverTimedOut(): int { return 0; }
};
$approvalService = new ApprovalService($approvalRepository, $service, new TransactionManager($pdo), null, $approvalQueue);
$approvalService->approve('default', $approval->id, 'manager-1', 'Approved');
if ($approvalRepository->approval?->status !== ApprovalStatus::Approved
    || $approvalAction->status !== ActionStatus::Queued
    || count($approvalQueue->enqueued) !== 1
    || $approvalQueue->enqueued[0]['type'] !== 'ACTION_EXECUTION'
    || $approvalQueue->enqueued[0]['payload']['action_id'] !== $approvalAction->id
) {
    throw new RuntimeException('Approval did not queue the Action.');
}
try {
    $approvalService->approve('default', $approval->id, 'manager-1');
    throw new RuntimeException('Repeated approval was accepted.');
} catch (DomainException) {
}

echo "Policy and Approval smoke test passed.\n";
