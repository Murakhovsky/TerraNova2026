<?php
declare(strict_types=1);

use Kernel\Event\Contract\EventConsumptionRepositoryInterface;
use Kernel\Event\Contract\EventHandlerInterface;
use Kernel\Event\Contract\EventOutboxInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use Kernel\Event\OutboxMessage;
use Kernel\Event\Service\DurableEventDispatcher;
use Kernel\Event\Service\OutboxPublisher;
use Kernel\Event\Service\OutboxReplayService;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Kernel\\')) {
        $file = $root . '/app/Kernel/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) require $file;
    }
});

$event = new DomainEvent(
    'event-1', 'tenant-a', 'sales.deal.created', 'deal', '42', [],
    new EventMetadata('correlation-1', null, 'SYSTEM', 'test'), new DateTimeImmutable(),
);
$events = new class($event) implements EventStoreInterface {
    public function __construct(private DomainEvent $event) {}
    public function append(DomainEvent $event): void {}
    public function find(string $eventId): ?DomainEvent { return $eventId === $this->event->id ? $this->event : null; }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array { return []; }
};
$outbox = new class implements EventOutboxInterface {
    public string $status = 'PENDING';
    public int $attempts = 0;
    public function claim(string $workerId): ?OutboxMessage {
        if (!in_array($this->status, ['PENDING', 'FAILED'], true)) return null;
        $this->status = 'PROCESSING';
        return new OutboxMessage(1, 'event-1', 'tenant-a', ++$this->attempts, $workerId);
    }
    public function markPublished(OutboxMessage $message): void { $this->status = 'PUBLISHED'; }
    public function markFailed(OutboxMessage $message, Throwable $error): void { $this->status = 'FAILED'; }
    public function recoverTimedOut(int $leaseSeconds = 300): int { return 0; }
    public function replay(?string $organizationId = null, ?string $eventId = null): int {
        if (($organizationId === null || $organizationId === 'tenant-a') && ($eventId === null || $eventId === 'event-1')) {
            $this->status = 'PENDING';
            $this->attempts = 0;
            return 1;
        }
        return 0;
    }
};
$consumptions = new class implements EventConsumptionRepositoryInterface {
    public array $states = [];
    public function begin(DomainEvent $event, string $consumerName): bool {
        $key = $event->id . ':' . $consumerName;
        if (($this->states[$key] ?? null) === 'COMPLETED') return false;
        $this->states[$key] = 'PROCESSING';
        return true;
    }
    public function complete(DomainEvent $event, string $consumerName): void { $this->states[$event->id . ':' . $consumerName] = 'COMPLETED'; }
    public function fail(DomainEvent $event, string $consumerName, Throwable $error): void { $this->states[$event->id . ':' . $consumerName] = 'FAILED'; }
    public function reset(?string $organizationId = null, ?string $eventId = null): int { $count = count($this->states); $this->states = []; return $count; }
};
$handler = new class implements EventHandlerInterface {
    public int $calls = 0;
    public function handle(DomainEvent $event): void {
        $this->calls++;
        if ($this->calls === 1) throw new RuntimeException('transient');
    }
};
$publisher = new OutboxPublisher(
    $outbox,
    $events,
    new DurableEventDispatcher($consumptions, ['sales-projector.v1' => $handler]),
);

if (!$publisher->runOne('worker-1') || $outbox->status !== 'FAILED' || $handler->calls !== 1) {
    throw new RuntimeException('Failed delivery was not retained for retry.');
}
if (!$publisher->runOne('worker-1') || $outbox->status !== 'PUBLISHED' || $handler->calls !== 2) {
    throw new RuntimeException('Outbox retry did not complete.');
}
if ($publisher->runOne('worker-1')) throw new RuntimeException('Published message was claimed again.');

$replayed = (new OutboxReplayService($outbox, $consumptions))->replay('tenant-a', 'event-1');
if ($replayed !== ['outbox' => 1, 'consumptions' => 1] || !$publisher->runOne('worker-1') || $handler->calls !== 3) {
    throw new RuntimeException('Coordinated event replay failed.');
}

echo "Durable outbox retry, idempotent consumption and replay passed.\n";

