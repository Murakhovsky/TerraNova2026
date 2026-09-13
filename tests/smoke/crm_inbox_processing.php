<?php
declare(strict_types=1);

use Domains\Sales\Application\Contract\CrmInboundApplierInterface;
use Domains\Sales\Application\Contract\CrmInboxRepositoryInterface;
use Domains\Sales\Application\Contract\MessageGatewayInterface;
use Domains\Sales\Application\Contract\SalesOperationRepositoryInterface;
use Domains\Sales\Application\DTO\CrmInboxItem;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\Service\SalesOperationService;
use Domains\Sales\Application\UseCase\ProcessCrmInbox;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Automation\Event\LeadChanged;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Kernel\\' => '/app/Kernel/', 'Domains\\' => '/app/Domains/'] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
        return;
    }
});

$makeTransactions = static fn (): TransactionManagerInterface => new class implements TransactionManagerInterface {
    private bool $active = false;
    private array $afterCommit = [];

    public function transactional(callable $operation): mixed
    {
        if ($this->active) return $operation();
        $this->active = true;
        try {
            $result = $operation();
            $callbacks = $this->afterCommit;
            $this->afterCommit = [];
            $this->active = false;
            foreach ($callbacks as $callback) $callback();
            return $result;
        } catch (Throwable $error) {
            $this->afterCommit = [];
            $this->active = false;
            throw $error;
        }
    }

    public function isActive(): bool { return $this->active; }
    public function afterCommit(callable $callback): void
    {
        if ($this->active) $this->afterCommit[] = $callback;
        else $callback();
    }
};

$makeStore = static fn (): EventStoreInterface => new class implements EventStoreInterface {
    /** @var list<DomainEvent> */
    public array $events = [];
    public function append(DomainEvent $event): void { $this->events[] = $event; }
    public function find(string $eventId): ?DomainEvent
    {
        foreach ($this->events as $event) if ($event->id === $eventId) return $event;
        return null;
    }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array
    {
        return array_slice(array_values(array_filter(
            $this->events,
            static fn (DomainEvent $event): bool => $event->organizationId === $organizationId
                && $event->aggregateType === $aggregateType
                && $event->aggregateId === $aggregateId,
        )), 0, $limit);
    }
};

$ruleContext = new class implements RuleContextProviderInterface {
    public function contextFor(DomainEvent $event): array { return []; }
};
$salesModule = new class($ruleContext) implements DomainModuleInterface, EventOwningModuleInterface {
    public function __construct(private RuleContextProviderInterface $rules) {}
    public function name(): string { return 'sales'; }
    public function eventTypes(): array { return [LeadChanged::TYPE, ClientCaseChanged::TYPE, SalesEventType::MESSAGE_RECEIVED]; }
    public function ruleContextProvider(): RuleContextProviderInterface { return $this->rules; }
};
$domains = new DomainModuleRegistry([$salesModule]);

$makeInbox = static fn (CrmInboxItem $item, bool $throwOnFail = false): CrmInboxRepositoryInterface => new class($item, $throwOnFail) implements CrmInboxRepositoryInterface {
    public array $completed = [];
    public array $failed = [];
    public function __construct(private CrmInboxItem $item, private bool $throwOnFail) {}
    public function receive(string $organizationId, string $provider, string $externalEventId, string $eventType, array $payload, string $correlationId): string { return $this->item->id; }
    public function claim(string $organizationId, string $id, string $workerId): ?CrmInboxItem
    {
        return $organizationId === $this->item->organizationId && $id === $this->item->id ? $this->item : null;
    }
    public function complete(CrmInboxItem $item): void { $this->completed[] = $item->id; }
    public function fail(CrmInboxItem $item, Throwable $error): void
    {
        $this->failed[] = [$item->id, $error->getMessage()];
        if ($this->throwOnFail) throw new RuntimeException('failure persistence failed');
    }
};

$makeApplier = static fn (?array $result = null, ?Throwable $error = null): CrmInboundApplierInterface => new class($result, $error) implements CrmInboundApplierInterface {
    public function __construct(private ?array $result, private ?Throwable $error) {}
    public function apply(CrmInboxItem $item): array
    {
        if ($this->error !== null) throw $this->error;
        return $this->result ?? [];
    }
};

$item = new CrmInboxItem('inbox-1', 'tenant-a', 'hubspot', 'ext-1', 'lead.updated', [], 1, 'corr-1');
$inbox = $makeInbox($item);
$applier = $makeApplier([
    'event_type' => LeadChanged::TYPE,
    'aggregate_type' => 'lead',
    'aggregate_id' => 'lead-17',
    'payload' => ['changes' => ['status' => 'QUALIFIED']],
]);
$transactions = $makeTransactions();
$store = $makeStore();
$events = new EventBus($store, $transactions);
$processor = new ProcessCrmInbox($inbox, $applier, $domains, $events, $transactions);
$processor->execute('tenant-a', 'inbox-1', 'smoke-worker');
if ($inbox->completed !== ['inbox-1'] || $inbox->failed !== [] || count($store->events) !== 1 || $store->events[0]->type !== LeadChanged::TYPE) {
    throw new RuntimeException('ProcessCrmInbox did not complete and publish the canonical Sales event.');
}

$foreignItem = new CrmInboxItem('inbox-2', 'tenant-a', 'hubspot', 'ext-2', 'message.received', [], 1, 'corr-2');
$foreignInbox = $makeInbox($foreignItem);
$foreignApplier = $makeApplier([
    'event_type' => 'finance.invoice.changed',
    'aggregate_type' => 'deal',
    'aggregate_id' => 'deal-1',
    'payload' => ['requested_message' => true, 'body' => 'must not be persisted', 'external_id' => 'msg-1'],
]);
$foreignTransactions = $makeTransactions();
$foreignStore = $makeStore();
$foreignProcessor = new ProcessCrmInbox($foreignInbox, $foreignApplier, $domains, new EventBus($foreignStore, $foreignTransactions), $foreignTransactions);
try {
    $foreignProcessor->execute('tenant-a', 'inbox-2', 'smoke-worker');
    throw new RuntimeException('Foreign CRM event ownership violation was accepted.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'not owned by Sales')) throw $error;
}
if ($foreignInbox->completed !== [] || count($foreignInbox->failed) !== 1 || $foreignStore->events !== []) {
    throw new RuntimeException('Foreign CRM event performed side effects before the Sales ownership boundary.');
}

$messageItem = new CrmInboxItem('inbox-3', 'tenant-a', 'hubspot', 'ext-3', 'deal.updated', [], 1, 'corr-3');
$messageInbox = $makeInbox($messageItem);
$messageApplier = $makeApplier([
    'event_type' => ClientCaseChanged::TYPE,
    'aggregate_type' => 'deal',
    'aggregate_id' => 'deal-3',
    'payload' => [
        'requested_message' => true,
        'channel' => 'email',
        'sender' => 'buyer@example.test',
        'recipient' => 'sales@example.test',
        'body' => 'Need details',
        'external_id' => 'message-3',
        'changes' => ['priority' => 'high'],
    ],
]);
$messageTransactions = $makeTransactions();
$messageStore = $makeStore();
$messageEvents = new EventBus($messageStore, $messageTransactions);
$gateway = new class implements MessageGatewayInterface {
    public function send(SendMessageCommand $command): OperationResult { return OperationResult::failure('not used'); }
};
$operationRepository = new class implements SalesOperationRepositoryInterface {
    public array $inbound = [];
    public function recordOutboundCommunication(string $organizationId, string $dealId, string $channel, string $body, string $externalId, string $idempotencyKey, array $metadata = []): ?string { return null; }
    public function recordInboundCommunication(string $organizationId, string $dealId, string $channel, string $sender, string $recipient, string $body, string $externalId, array $metadata = []): ?string
    {
        $this->inbound[] = compact('organizationId', 'dealId', 'channel', 'externalId');
        return 'communication-3';
    }
    public function scheduleMeeting(string $organizationId, string $dealId, string $title, DateTimeImmutable $scheduledAt, string $idempotencyKey, array $metadata = []): ?string { return null; }
    public function completeActivity(string $organizationId, string $dealId, int $activityId, ?int $userId): ?array { return null; }
    public function rescheduleActivity(string $organizationId, string $dealId, int $activityId, DateTimeImmutable $dueAt): bool { return false; }
};
$operations = new SalesOperationService($gateway, $operationRepository, $messageEvents, $messageTransactions);
$messageProcessor = new ProcessCrmInbox($messageInbox, $messageApplier, $domains, $messageEvents, $messageTransactions, null, null, $operations);
$messageProcessor->execute('tenant-a', 'inbox-3', 'smoke-worker');
$types = array_map(static fn (DomainEvent $event): string => $event->type, $messageStore->events);
if ($operationRepository->inbound === [] || $types !== [SalesEventType::MESSAGE_RECEIVED, ClientCaseChanged::TYPE]) {
    throw new RuntimeException('Canonical message handling suppressed the distinct mapped Sales event.');
}
if (($messageStore->events[1]->payload['requested_message'] ?? false) === true) {
    throw new RuntimeException('Internal CRM requested_message directive leaked into the published Sales event.');
}

$failureItem = new CrmInboxItem('inbox-4', 'tenant-a', 'hubspot', 'ext-4', 'lead.updated', [], 1, 'corr-4');
$failureInbox = $makeInbox($failureItem, true);
$failureTransactions = $makeTransactions();
$failureStore = $makeStore();
$failureProcessor = new ProcessCrmInbox(
    $failureInbox,
    $makeApplier(null, new RuntimeException('primary apply failure')),
    $domains,
    new EventBus($failureStore, $failureTransactions),
    $failureTransactions,
);
try {
    $failureProcessor->execute('tenant-a', 'inbox-4', 'smoke-worker');
    throw new RuntimeException('Expected CRM apply failure was not thrown.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'primary apply failure') {
        throw new RuntimeException('Inbox failure persistence masked the primary processing failure: ' . $error->getMessage());
    }
}
if (count($failureInbox->failed) !== 1) throw new RuntimeException('CRM processing failure was not reported to the inbox repository.');

echo "Sales CRM inbox processing smoke passed: ownership, canonical events and failure semantics are preserved.\n";
