<?php
declare(strict_types=1);

use Domains\Sales\Automation\Event\DealCreated;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Automation\Event\FollowupOverdue;
use Domains\Sales\Automation\Policy\SalesPolicyCatalog;
use Domains\Sales\Automation\Rule\SalesRuleCatalog;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use Kernel\Policy\PolicyDecision;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Rule\Service\ConditionEvaluator;
use Kernel\Rule\Service\DeterministicProcessEngine;

$root = dirname(__DIR__, 2);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'Kernel\\' => '/app/Kernel/',
        'Domains\\' => '/app/Domains/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

$metadata = new EventMetadata('correlation-1', null, 'SYSTEM', 'smoke-test');
$engine = new DeterministicProcessEngine(new ConditionEvaluator());
$catalog = new SalesRuleCatalog();
$rules = $catalog->rules('default');
$policies = (new SalesPolicyCatalog())->policies('default');
$policyEngine = new PolicyEngine(new ConditionEvaluator());
$executor = new ActionExecutor([
    new class implements ActionHandlerInterface {
        public function supports(string $actionType): bool
        {
            return str_starts_with($actionType, 'sales.');
        }

        public function execute(Action $action): ExecutionResult
        {
            return ExecutionResult::success(['simulated' => true, 'action_type' => $action->type]);
        }
    },
]);

$scenarios = [
    [DealCreated::TYPE, 'deal-1', ['deal' => ['status' => 'active', 'stage' => 'new']], 'sales.create_qualification_task'],
    [DealStageChanged::TYPE, 'deal-2', ['deal' => ['status' => 'active', 'stage' => 'qualification', 'next_contact_at' => null]], 'sales.create_followup_task'],
    [FollowupOverdue::TYPE, 'deal-3', ['deal' => ['status' => 'active'], 'activity' => ['completed_at' => null, 'is_overdue' => true]], 'sales.escalate_overdue_followup'],
];

foreach ($scenarios as $index => [$type, $dealId, $context, $expectedAction]) {
    $event = new DomainEvent('event-' . $index, 'default', $type, 'deal', $dealId, [], $metadata, new DateTimeImmutable());
    $matched = array_values(array_filter(
        $engine->evaluate($event, $context, $rules),
        static fn ($evaluation): bool => $evaluation->matched,
    ));

    if (count($matched) !== 1 || $matched[0]->proposal?->type !== $expectedAction) {
        throw new RuntimeException(sprintf('Scenario %s did not produce %s.', $type, $expectedAction));
    }

    $proposal = $matched[0]->proposal;
    $policy = $policyEngine->decide($proposal->type, ['action' => ['risk_level' => $proposal->riskLevel]], $policies);
    if ($policy !== PolicyDecision::Auto) {
        throw new RuntimeException(sprintf('Scenario %s was not allowed by an AUTO policy.', $type));
    }

    $action = new Action(
        'action-' . $index,
        'default',
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
    );
    $action->transitionTo(ActionStatus::Queued);
    $result = $executor->execute($action);
    if (!$result->successful || $action->status !== ActionStatus::Completed) {
        throw new RuntimeException(sprintf('Scenario %s did not complete its Action.', $type));
    }
}

$negative = new DomainEvent('event-negative', 'default', DealStageChanged::TYPE, 'deal', 'deal-4', [], $metadata, new DateTimeImmutable());
$matched = array_filter(
    $engine->evaluate($negative, ['deal' => ['status' => 'active', 'stage' => 'qualification', 'next_contact_at' => '2026-08-23 10:00:00']], $rules),
    static fn ($evaluation): bool => $evaluation->matched,
);
if ($matched !== []) {
    throw new RuntimeException('A Deal with a next contact must not produce a follow-up Action.');
}

echo "Deterministic Sales processes passed: 3 full loops, 1 negative.\n";
