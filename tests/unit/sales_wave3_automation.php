<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        foreach ([
            'Kernel\\' => '/app/Kernel/',
            'Platform\\' => '/app/Platform/',
            'Domains\\' => '/app/Domains/',
            'Infrastructure\\' => '/app/Infrastructure/',
        ] as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) continue;
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
            return;
        }
    });
}

use DateTimeImmutable;
use Domains\Sales\Application\Contract\LeadFollowupRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleLeadFollowupCommand;
use Domains\Sales\Application\UseCase\ScheduleLeadFollowup;
use Domains\Sales\Automation\Action\CreateLeadFollowupTaskHandler;
use Domains\Sales\Automation\Policy\SalesPolicyCatalog;
use Domains\Sales\Automation\Rule\SalesRuleCatalog;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

function wave3(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$events = [];
$calls = [];
$repo = new class($calls) implements LeadFollowupRepositoryInterface {
    public function __construct(private array &$calls) {}
    public function schedule(ScheduleLeadFollowupCommand $command): OperationResult
    {
        $this->calls[] = $command;
        return OperationResult::success('9001', ['duplicate' => false]);
    }
};
$tx = new class implements TransactionManagerInterface {
    private bool $active = false;
    public function transactional(callable $operation): mixed
    {
        $previous = $this->active;
        $this->active = true;
        try { return $operation(); } finally { $this->active = $previous; }
    }
    public function isActive(): bool { return $this->active; }
    public function afterCommit(callable $callback): void { $callback(); }
};
$store = new class($events) implements EventStoreInterface {
    public function __construct(private array &$events) {}
    public function append(DomainEvent $event): void { $this->events[] = $event; }
    public function find(string $eventId): ?DomainEvent { return null; }
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100): array { return []; }
};

$useCase = new ScheduleLeadFollowup($repo, new EventBus($store, $tx), $tx);
$handler = new CreateLeadFollowupTaskHandler($useCase);
$action = new Action(
    'action-wave3',
    'org-1',
    CreateLeadFollowupTaskHandler::TYPE,
    'lead',
    '301',
    ['title' => 'Contact Lead', 'due_in_minutes' => 30],
    'RULE',
    'rule-wave3',
    'AUTO',
    'LOW',
    'idem-wave3',
    new DateTimeImmutable('2026-09-18T10:00:00Z'),
    ActionStatus::Queued,
    'corr-wave3',
);

$result = $handler->execute($action);
wave3($result->successful, 'Lead follow-up action must execute successfully.');
wave3(count($calls) === 1, 'Lead follow-up action must invoke the canonical use case once.');
wave3($calls[0]->organizationId === 'org-1' && $calls[0]->leadReference === '301', 'Lead follow-up must preserve tenant/lead scope.');
wave3($calls[0]->idempotencyKey === 'idem-wave3', 'Lead follow-up must preserve action idempotency.');
wave3(count($events) === 1 && $events[0]->type === 'sales.task.created', 'Lead follow-up must emit canonical task event.');
wave3($events[0]->aggregateType === 'lead' && $events[0]->aggregateId === '301', 'Lead task event aggregate is wrong.');
wave3($events[0]->metadata->correlationId === 'corr-wave3', 'Lead task event must preserve correlation.');

$rules = (new SalesRuleCatalog())->rules('org-1');
$leadRule = array_values(array_filter($rules, static fn ($rule): bool => $rule->trigger === 'sales.lead.created'));
wave3(count($leadRule) === 1, 'Wave 3 must define exactly one bootstrap new-Lead rule.');
wave3(($leadRule[0]->effect['action_type'] ?? null) === CreateLeadFollowupTaskHandler::TYPE, 'New Lead rule must propose the lead follow-up action.');

$policies = (new SalesPolicyCatalog())->policies('org-1');
$auto = array_values(array_filter($policies, static fn ($policy): bool => $policy->actionType === CreateLeadFollowupTaskHandler::TYPE));
wave3(count($auto) === 1 && $auto[0]->decision->value === 'AUTO', 'Lead follow-up action must have an AUTO policy.');

echo "Sales Wave 3 deterministic automation unit contract passed.\n";
