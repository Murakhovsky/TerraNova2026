<?php
declare(strict_types=1);

use Kernel\Action\Action;
use Kernel\Action\ActionExecutionClaim;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionExecutionGateInterface;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\Contract\ActionRepositoryInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;
use Kernel\Execution\ExecutionFailureException;
use Kernel\Execution\ExecutionFailureKind;
use Kernel\Module\KernelVersion;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Kernel\Queue\Job;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class OutcomeActionRepository implements ActionRepositoryInterface
{
    public int $attempt = 0;
    public ?ExecutionResult $lastResult = null;

    public function __construct(public ?Action $action) {}

    public function save(Action $action): Action
    {
        $this->action = $action;
        return $action;
    }

    public function find(string $organizationId, string $id): ?Action
    {
        if ($this->action?->organizationId !== $organizationId || $this->action?->id !== $id) {
            return null;
        }
        return $this->action;
    }

    public function existsByIdempotencyKey(string $organizationId, string $key): bool
    {
        return $this->action?->organizationId === $organizationId
            && $this->action?->idempotencyKey === $key;
    }

    public function transition(string $organizationId, string $id, ActionStatus $from, ActionStatus $to): bool
    {
        $action = $this->find($organizationId, $id);
        if ($action === null || $action->status !== $from) return false;
        $action->transitionTo($to);
        return true;
    }

    public function claim(string $organizationId, string $id, string $workerId): ?ActionExecutionClaim
    {
        $action = $this->find($organizationId, $id);
        if ($action === null || $action->status !== ActionStatus::Queued) return null;
        $action->transitionTo(ActionStatus::Running);
        $this->attempt++;
        return new ActionExecutionClaim($action, $this->attempt, $workerId);
    }

    public function claimNext(string $workerId): ?ActionExecutionClaim
    {
        $action = $this->action;
        if ($action === null) return null;
        return $this->claim($action->organizationId, $action->id, $workerId);
    }

    public function finish(ActionExecutionClaim $claim, ExecutionResult $result): void
    {
        $this->lastResult = $result;
    }

    public function requeueStale(int $olderThanSeconds): int
    {
        return 0;
    }
}

final readonly class AllowOutcomeActionExecution implements ActionExecutionGateInterface
{
    public function assertExecutable(Action $action): void {}
}

final class FixedOutcomeActionHandler implements ActionHandlerInterface
{
    public int $calls = 0;

    public function __construct(private readonly ExecutionResult $result) {}

    public function supports(string $actionType): bool
    {
        return $actionType === 'test.action';
    }

    public function execute(Action $action): ExecutionResult
    {
        $this->calls++;
        return $this->result;
    }
}

function outcomeAction(string $id): Action
{
    return new Action(
        $id,
        'org-1',
        'test.action',
        'test',
        'subject-1',
        [],
        'TEST',
        'source-1',
        'AUTO',
        'LOW',
        $id,
        new DateTimeImmutable(),
        ActionStatus::Queued,
        'corr-' . $id,
    );
}

/** @return array{ActionService, OutcomeActionRepository, FixedOutcomeActionHandler} */
function outcomeService(Action $action, ExecutionResult $result): array
{
    $repository = new OutcomeActionRepository($action);
    $handler = new FixedOutcomeActionHandler($result);
    $service = new ActionService(
        $repository,
        new ActionExecutor([$handler], new AllowOutcomeActionExecution()),
    );
    return [$service, $repository, $handler];
}

function assertOutcome(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

assertOutcome(
    version_compare(KernelVersion::VERSION, '0.11.6', '>='),
    'Action execution outcome propagation requires Kernel 0.11.6+.',
);

[$service, $repository] = outcomeService(
    outcomeAction('action-outcome'),
    ExecutionResult::failure('upstream down', failureKind: ExecutionFailureKind::ExternalUnavailable),
);
$outcome = $service->executeOutcome('org-1', 'action-outcome', 'worker-1');
assertOutcome($outcome !== null, 'ActionService must expose the claimed execution outcome.');
assertOutcome($outcome->claim->attempt === 1, 'Execution outcome lost claim attempt identity.');
assertOutcome($outcome->result->failureKind === ExecutionFailureKind::ExternalUnavailable, 'Execution outcome lost failure kind.');
assertOutcome($outcome->action()->status === ActionStatus::Failed, 'Failed outcome must preserve the failed action state.');
assertOutcome($repository->lastResult === $outcome->result, 'Repository finish and returned outcome must share the same result.');

[$service] = outcomeService(outcomeAction('action-success'), ExecutionResult::success(['ok' => true]));
$legacyAction = $service->execute('org-1', 'action-success', 'worker-legacy');
assertOutcome($legacyAction?->status === ActionStatus::Completed, 'Legacy ActionService::execute() facade changed behavior.');

[$service, , $handler] = outcomeService(
    outcomeAction('action-permanent'),
    ExecutionResult::failure('invalid payload', failureKind: ExecutionFailureKind::Permanent),
);
$jobHandler = new ActionExecutionJobHandler($service);
try {
    $jobHandler->handle(new Job(
        'job-permanent', 'org-1', ActionExecutionJobHandler::TYPE,
        ['action_id' => 'action-permanent'], 1, 5, 60, 'corr-permanent', null, 'worker-1',
    ));
    throw new RuntimeException('Permanent action failure must escape the queue handler.');
} catch (ExecutionFailureException $exception) {
    assertOutcome($exception->failureKind() === ExecutionFailureKind::Permanent, 'Permanent action failure was reclassified.');
}
assertOutcome($handler->calls === 1, 'Permanent action failure should execute once for the claimed job.');

[$service, $repository, $handler] = outcomeService(
    outcomeAction('action-external'),
    ExecutionResult::failure('provider unavailable', failureKind: ExecutionFailureKind::ExternalUnavailable),
);
$jobHandler = new ActionExecutionJobHandler($service);
foreach ([1, 2] as $attempt) {
    try {
        $jobHandler->handle(new Job(
            'job-external', 'org-1', ActionExecutionJobHandler::TYPE,
            ['action_id' => 'action-external'], $attempt, 5, 60, 'corr-external', null, 'worker-2',
        ));
        throw new RuntimeException('Retryable action failure must escape the queue handler.');
    } catch (ExecutionFailureException $exception) {
        assertOutcome(
            $exception->failureKind() === ExecutionFailureKind::ExternalUnavailable,
            'External action failure lost retryable classification.',
        );
    }
}
assertOutcome($handler->calls === 2, 'Retrying a failed action must execute a new action attempt.');
assertOutcome($repository->attempt === 2, 'Retrying a failed action must create a new execution claim.');

[$service] = outcomeService(outcomeAction('action-invalid-job'), ExecutionResult::success());
try {
    (new ActionExecutionJobHandler($service))->handle(new Job(
        'job-invalid', 'org-1', ActionExecutionJobHandler::TYPE,
        [], 1, 5, 60, 'corr-invalid', null, 'worker-3',
    ));
    throw new RuntimeException('Missing action id must fail permanently.');
} catch (ExecutionFailureException $exception) {
    assertOutcome($exception->failureKind() === ExecutionFailureKind::Permanent, 'Malformed action job must be permanent.');
}

echo "Action execution outcome propagation passed.\n";
