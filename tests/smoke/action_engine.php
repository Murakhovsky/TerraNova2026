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

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Kernel\\')) {
        $file = $root . '/app/Kernel/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) require $file;
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

echo "Action Engine smoke test passed.\n";
