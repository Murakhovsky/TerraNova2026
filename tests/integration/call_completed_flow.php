<?php
declare(strict_types=1);

use Domains\Sales\Agent\SalesIntelligenceAgent;
use Domains\Sales\Event\CallCompleted;
use Infrastructure\Database\Rule\MysqlActionProposalSink;
use Infrastructure\Database\Transaction\TransactionManager;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\Contract\ActionRepositoryInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\AgentResult;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Agent\Contract\AgentRunRepositoryInterface;
use Kernel\Agent\Contract\LlmClientInterface;
use Kernel\Agent\Contract\DecisionRepositoryInterface;
use Kernel\Agent\LlmResponse;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Agent\Service\StructuredDecisionValidator;
use Kernel\Approval\Approval;
use Kernel\Approval\ApprovalStatus;
use Kernel\Approval\Contract\ApprovalRepositoryInterface;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\Contract\PolicyEvaluationRepositoryInterface;
use Kernel\Policy\Contract\PolicyRepositoryInterface;
use Kernel\Policy\PolicyDecision;
use Kernel\Policy\PolicyEvaluation;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Kernel\Queue\Handler\AgentRunJobHandler;
use Kernel\Queue\Job;
use Kernel\Queue\Service\QueueWorker;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Kernel\Rule\Contract\RuleEvaluationRepositoryInterface;
use Kernel\Rule\Contract\RuleRepositoryInterface;
use Kernel\Rule\ProcessEvaluation;
use Kernel\Rule\Rule;
use Kernel\Rule\Service\ConditionEvaluator;
use Kernel\Rule\Service\DeterministicProcessEngine;
use Kernel\Rule\Service\RuleEngineEventHandler;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Kernel\\' => '/app/Kernel/', 'Domains\\' => '/app/Domains/', 'Infrastructure\\' => '/app/Infrastructure/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});

$pdo = new PDO('sqlite::memory:');
$transactions = new TransactionManager($pdo);

$eventStore = new class implements EventStoreInterface {
    /** @var list<DomainEvent> */ public array $events = [];
    public function append(DomainEvent $event): void { $this->events[] = $event; }
    public function find(string $eventId): ?DomainEvent { foreach ($this->events as $event) if ($event->id === $eventId) return $event; return null; }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array {
        return array_slice(array_values(array_filter($this->events, static fn (DomainEvent $event): bool =>
            $event->organizationId === $organizationId && $event->aggregateType === $aggregateType && $event->aggregateId === $aggregateId
        )), 0, $limit);
    }
};

$actionRepository = new class implements ActionRepositoryInterface {
    /** @var array<string, Action> */ public array $actions = [];
    /** @var array<string, string> */ private array $keys = [];
    public function save(Action $action): Action {
        if ($action->idempotencyKey !== null && isset($this->keys[$action->organizationId . ':' . $action->idempotencyKey])) {
            return $this->actions[$this->keys[$action->organizationId . ':' . $action->idempotencyKey]];
        }
        $this->actions[$action->id] = $action;
        if ($action->idempotencyKey !== null) $this->keys[$action->organizationId . ':' . $action->idempotencyKey] = $action->id;
        return $action;
    }
    public function find(string $organizationId, string $id): ?Action { $action = $this->actions[$id] ?? null; return $action?->organizationId === $organizationId ? $action : null; }
    public function existsByIdempotencyKey(string $organizationId, string $key): bool { return isset($this->keys[$organizationId . ':' . $key]); }
    public function transition(string $organizationId, string $id, ActionStatus $from, ActionStatus $to): bool {
        $action = $this->find($organizationId, $id); if ($action === null || $action->status !== $from) return false; $action->transitionTo($to); return true;
    }
    public function claim(string $organizationId, string $id, string $workerId): ?Action {
        $action = $this->find($organizationId, $id); if ($action === null || $action->status !== ActionStatus::Queued) return null; $action->transitionTo(ActionStatus::Running); return $action;
    }
    public function claimNext(string $workerId): ?Action { foreach ($this->actions as $action) if ($action->status === ActionStatus::Queued) return $this->claim($action->organizationId, $action->id, $workerId); return null; }
    public function finish(Action $action, ExecutionResult $result): void {}
    public function requeueStale(int $olderThanSeconds): int { return 0; }
};

$audit = new class implements AuditRepositoryInterface {
    /** @var list<AuditEntry> */ public array $entries = [];
    public function append(AuditEntry $entry): void { $this->entries[] = $entry; }
};
$executed = [];
$actionHandler = new class($executed) implements ActionHandlerInterface {
    public function __construct(private array &$executed) {}
    public function supports(string $actionType): bool { return $actionType === 'sales.send_followup'; }
    public function execute(Action $action): ExecutionResult {
        $this->executed[] = $action->id;
        return ExecutionResult::success(['message_id' => 'msg-1', 'channel' => $action->parameters['channel'] ?? 'telegram'], ['messages_sent' => 1]);
    }
};
$actionService = new ActionService($actionRepository, new ActionExecutor([$actionHandler]), $eventStore, $audit, $transactions);

$policyEvaluations = [];
$policies = new class implements PolicyRepositoryInterface {
    public function activeFor(string $organizationId, string $actionType): array {
        return [new ActionPolicy('followup-auto', $organizationId, $actionType, [], PolicyDecision::Auto, 1)];
    }
};
$evaluationRepository = new class($policyEvaluations) implements PolicyEvaluationRepositoryInterface {
    public function __construct(private array &$items) {}
    public function save(Action $action, PolicyEvaluation $evaluation, array $context): void { $this->items[] = $evaluation; }
};
$approvals = new class implements ApprovalRepositoryInterface {
    public function createFor(Action $action, string $approverType, string $approverId, string $reason): Approval { throw new RuntimeException('AUTO action must not create approval.'); }
    public function findPending(string $organizationId, string $approvalId): ?Approval { return null; }
    public function decide(string $organizationId, string $approvalId, ApprovalStatus $decision, string $userId, ?string $note): bool { return false; }
};
$policyService = new ActionPolicyService(
    $actionService, $policies, $evaluationRepository, $approvals,
    new PolicyEngine(new ConditionEvaluator()), $transactions, $audit,
);

$queue = new class implements JobQueueInterface {
    /** @var array<string, array{job: Job, status: string, available: bool, error: ?string}> */ public array $jobs = [];
    /** @var array<string, string> */ private array $keys = [];
    public function enqueue(string $organizationId, string $type, array $payload, string $correlationId, ?string $idempotencyKey = null, int $maxAttempts = 5, int $timeoutSeconds = 60): string {
        $key = $organizationId . ':' . $idempotencyKey;
        if ($idempotencyKey !== null && isset($this->keys[$key])) return $this->keys[$key];
        $id = 'job-' . (count($this->jobs) + 1);
        $this->jobs[$id] = ['job' => new Job($id, $organizationId, $type, $payload, 0, $maxAttempts, $timeoutSeconds, $correlationId, $idempotencyKey), 'status' => 'PENDING', 'available' => true, 'error' => null];
        if ($idempotencyKey !== null) $this->keys[$key] = $id;
        return $id;
    }
    public function claim(string $workerId): ?Job {
        foreach ($this->jobs as $id => $record) {
            if (!in_array($record['status'], ['PENDING', 'FAILED'], true) || !$record['available']) continue;
            $old = $record['job'];
            $job = new Job($old->id, $old->organizationId, $old->type, $old->payload, $old->attempts + 1, $old->maxAttempts, $old->timeoutSeconds, $old->correlationId, $old->idempotencyKey, $workerId);
            $this->jobs[$id]['job'] = $job; $this->jobs[$id]['status'] = 'RUNNING'; return $job;
        }
        return null;
    }
    public function complete(Job $job): void { $this->jobs[$job->id]['status'] = 'COMPLETED'; }
    public function fail(Job $job, string $error): void { $this->jobs[$job->id]['status'] = $job->attempts >= $job->maxAttempts ? 'DEAD' : 'FAILED'; $this->jobs[$job->id]['error'] = $error; }
    public function recoverTimedOut(): int { return 0; }
};

$agentRuns = [];
$contextBuilder = new class implements AgentContextBuilderInterface {
    public function build(AgentInvocation $invocation): array { return ['deal' => ['id' => $invocation->subjectId, 'stage' => 'qualified'], 'call' => ['result' => 'client_thinking']]; }
};
$llm = new class implements LlmClientInterface {
    public int $calls = 0;
    public function structured(AgentDefinition $agent, string $question, array $context): LlmResponse {
        $this->calls++;
        return new LlmResponse([
            'decision' => 'FOLLOW_UP', 'reason' => 'Buying intent exists and no next contact is scheduled.', 'confidence' => 0.91,
            'proposed_actions' => [['type' => 'sales.send_followup', 'parameters' => ['channel' => 'telegram', 'body' => 'Financing options']]],
        ], 'fake', 'fake-structured');
    }
};
$runRepository = new class($agentRuns) implements AgentRunRepositoryInterface {
    public function __construct(private array &$runs) {}
    public function start(string $runId, AgentDefinition $agent, AgentInvocation $invocation, array $context): void { $this->runs[] = 'RUNNING'; }
    public function complete(string $runId, AgentResult $result, LlmResponse $response, int $durationMs): void { $this->runs[] = 'COMPLETED'; }
    public function fail(string $runId, Throwable $error, int $durationMs, bool $invalidOutput = false): void { $this->runs[] = 'FAILED'; }
};
$decisions = [];
$decisionRepository = new class($decisions) implements DecisionRepositoryInterface {
    public function __construct(private array &$decisions) {}
    public function save(string $runId, AgentDefinition $agent, AgentInvocation $invocation, AgentResult $result): string {
        $this->decisions[] = ['run_id' => $runId, 'decision' => $result->decision, 'correlation_id' => $invocation->correlationId];
        return 'decision-1';
    }
};
$agentRuntime = new AgentRuntime($contextBuilder, $llm, new StructuredDecisionValidator(), $runRepository, $decisionRepository);

$ruleEvaluations = [];
$rule = new Rule(
    'call-completed-agent-v1', 'default', 'Analyze completed call', CallCompleted::TYPE, [],
    ['type' => 'CREATE_ACTION', 'action_type' => 'agent.run.sales_intelligence', 'target_type' => '{{event.aggregate_type}}', 'target_id' => '{{event.aggregate_id}}', 'parameters' => ['question' => 'What is the best next step?'], 'execution_mode' => 'AUTO', 'risk_level' => 'LOW'],
    1, 1,
);
$ruleRepository = new class($rule) implements RuleRepositoryInterface {
    public function __construct(private Rule $rule) {}
    public function activeFor(string $organizationId, string $trigger): array { return $trigger === $this->rule->trigger ? [$this->rule] : []; }
};
$ruleContexts = new class implements RuleContextProviderInterface { public function contextFor(DomainEvent $event): array { return ['deal' => ['id' => $event->aggregateId]]; } };
$ruleEvaluationRepository = new class($ruleEvaluations) implements RuleEvaluationRepositoryInterface {
    public function __construct(private array &$items) {}
    public function save(DomainEvent $event, ProcessEvaluation $evaluation, array $context): void { $this->items[] = $evaluation; }
};
$sink = new MysqlActionProposalSink($policyService, $queue);
$ruleHandler = new RuleEngineEventHandler($ruleRepository, $ruleContexts, $ruleEvaluationRepository, $sink, new DeterministicProcessEngine(new ConditionEvaluator()));

$workerId = 'integration-worker';
$worker = new QueueWorker($queue, [
    new AgentRunJobHandler($agentRuntime, SalesIntelligenceAgent::definition(), $policyService, $queue),
    new ActionExecutionJobHandler($actionService),
]);
$bus = new EventBus($eventStore, $transactions);
$bus->subscribe(CallCompleted::TYPE, $ruleHandler);

$bus->publish(CallCompleted::create(
    'event-call-1', 'default', 'deal-184', 421, 'client_thinking',
    new EventMetadata('correlation-184', null, 'USER', 'manager-7'), 'transcript-9',
));
while ($worker->runOne($workerId)) {}

$eventTypes = array_map(static fn (DomainEvent $event): string => $event->type, $eventStore->events);
$jobStatuses = array_values(array_map(static fn (array $record): string => $record['status'], $queue->jobs));
$action = array_values($actionRepository->actions)[0] ?? null;
$auditCategories = array_map(static fn (AuditEntry $entry): string => $entry->category, $audit->entries);

if ($eventTypes !== ['sales.call.completed', 'sales.followup.sent']) throw new RuntimeException('Expected CallCompleted and FollowupSent events.');
if ($jobStatuses !== ['COMPLETED', 'COMPLETED']) throw new RuntimeException('Agent and Action jobs did not complete: ' . json_encode($queue->jobs));
if (count($ruleEvaluations) !== 1 || !$ruleEvaluations[0]->matched) throw new RuntimeException('Rule did not match.');
if ($agentRuns !== ['RUNNING', 'COMPLETED'] || $llm->calls !== 1) throw new RuntimeException('Agent did not complete exactly once.');
if (count($decisions) !== 1 || $decisions[0]['correlation_id'] !== 'correlation-184') throw new RuntimeException('Structured Decision was not persisted in the audit chain.');
if (count($policyEvaluations) !== 1 || $policyEvaluations[0]->decision !== PolicyDecision::Auto) throw new RuntimeException('AUTO policy was not applied.');
if ($action?->status !== ActionStatus::Completed || count($executed) !== 1) throw new RuntimeException('Follow-up Action was not executed exactly once.');
if (!in_array('POLICY_DECISION', $auditCategories, true) || !in_array('ACTION_EXECUTION', $auditCategories, true)) throw new RuntimeException('Audit chain is incomplete.');

echo "CallCompleted integration flow passed: Event -> Rule -> Agent job -> Decision -> Action job -> Policy -> Executor -> Result Event -> Audit.\n";
