<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/symfony/src/Application/System/Service/SalesOutboxDrainer.php';

use App\Application\System\Service\SalesOutboxDrainer;
use DateTimeImmutable;
use Kernel\Event\Contract\EventConsumptionRepositoryInterface;
use Kernel\Event\Contract\EventOutboxInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use Kernel\Event\OutboxMessage;
use Kernel\Event\Service\DurableEventDispatcher;
use Kernel\Event\Service\OutboxPublisher;
use Throwable;

final class Wave3Outbox implements EventOutboxInterface
{
    /** @var list<OutboxMessage|null> */
    private array $claims;
    /** @var list<string> */
    public array $published = [];

    /** @param list<OutboxMessage|null> $claims */
    public function __construct(array $claims)
    {
        $this->claims = $claims;
    }

    public function claim(string $workerId): ?OutboxMessage
    {
        if ($this->claims === []) {
            return null;
        }
        return array_shift($this->claims);
    }

    public function markPublished(OutboxMessage $message): void
    {
        $this->published[] = $message->eventId;
    }

    public function markFailed(OutboxMessage $message, Throwable $error): void
    {
        throw new RuntimeException('Wave 3 drainer test must not fail an event: ' . $error->getMessage());
    }

    public function recoverTimedOut(int $leaseSeconds = 300): int { return 0; }
    public function replay(?string $organizationId = null, ?string $eventId = null): int { return 0; }
}

final class Wave3EventStore implements EventStoreInterface
{
    /** @var array<string,DomainEvent> */
    private array $events = [];

    /** @param list<DomainEvent> $events */
    public function __construct(array $events)
    {
        foreach ($events as $event) {
            $this->events[$event->id] = $event;
        }
    }

    public function append(DomainEvent $event): void { $this->events[$event->id] = $event; }
    public function find(string $eventId): ?DomainEvent { return $this->events[$eventId] ?? null; }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array { return []; }
}

final class Wave3Consumptions implements EventConsumptionRepositoryInterface
{
    public function begin(DomainEvent $event, string $consumerName): bool { return true; }
    public function complete(DomainEvent $event, string $consumerName): void {}
    public function fail(DomainEvent $event, string $consumerName, Throwable $error): void {}
    public function reset(?string $organizationId = null, ?string $eventId = null): int { return 0; }
}

$event = static function (string $id): DomainEvent {
    return new DomainEvent(
        $id,
        'default',
        'sales.test',
        'deal',
        '101',
        [],
        new EventMetadata('corr-' . $id, null, 'SYSTEM', 'wave3-test'),
        new DateTimeImmutable('2026-09-18T12:00:00Z'),
    );
};

$events = [$event('event-1'), $event('event-2')];
$outbox = new Wave3Outbox([
    new OutboxMessage(1, 'event-1', 'default', 1, 'wave3-worker'),
    null, // Simulates a transient SKIP LOCKED / concurrent claim window.
    new OutboxMessage(2, 'event-2', 'default', 1, 'wave3-worker'),
    null,
    null,
    null,
]);
$dispatcher = new DurableEventDispatcher(new Wave3Consumptions(), []);
$publisher = new OutboxPublisher($outbox, new Wave3EventStore($events), $dispatcher, recoveryIntervalSeconds: 3600);
$drainer = new SalesOutboxDrainer($publisher);

$processed = $drainer->drain(10, 'wave3-worker', idleRetries: 2, idleDelayMicroseconds: 0);
if ($processed !== 2) {
    throw new RuntimeException('Wave 3 drainer stopped on a transient empty claim.');
}
if ($outbox->published !== ['event-1', 'event-2']) {
    throw new RuntimeException('Wave 3 drainer did not publish both events in order.');
}

echo "Sales Wave 3 deterministic outbox drainer contract passed.\n";
