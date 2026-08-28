<?php
declare(strict_types=1);

use Domains\Sales\Automation\Event\DealStageChanged;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Infrastructure\Persistence\MySql\Database\Transaction\TransactionManager;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'Kernel\\' => '/app/Kernel/',
        'Domains\\' => '/app/Domains/',
        'Infrastructure\\' => '/app/Infrastructure/',
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

$calls = [];
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE business_changes (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE stored_events (id TEXT PRIMARY KEY, type TEXT NOT NULL)');
$transactions = new TransactionManager($pdo);

$store = new class($calls, $pdo) implements EventStoreInterface {
    public function __construct(private array &$calls, private PDO $pdo)
    {
    }

    public function append(DomainEvent $event): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Event was not stored in the business transaction.');
        }
        $statement = $this->pdo->prepare('INSERT INTO stored_events (id, type) VALUES (:id, :type)');
        $statement->execute(['id' => $event->id, 'type' => $event->type]);
        $this->calls[] = ['store', $event->type, $event->aggregateId];
    }

    public function find(string $eventId): ?DomainEvent
    {
        return null;
    }

    public function findByAggregate(
        string $organizationId,
        string $aggregateType,
        string $aggregateId,
        int $limit = 100,
    ): array {
        return [];
    }
};

$event = DealStageChanged::create(
    'event-001',
    'organization-001',
    '184',
    'qualification',
    'negotiation',
    new EventMetadata('correlation-001', null, 'USER', 'manager-001'),
);

$bus = new EventBus($store, $transactions);
$transactions->transactional(function () use ($pdo, $bus, $event): void {
    $pdo->exec('INSERT INTO business_changes (id) VALUES (1)');
    $bus->publish($event);
});

$expected = [
    ['store', 'sales.deal.stage_changed', '184'],
];

if ($calls !== $expected) {
    throw new RuntimeException('Event Bus smoke test failed: ' . json_encode($calls));
}

$rolledBackEvent = DealStageChanged::create(
    'event-rollback',
    'organization-001',
    '185',
    'new',
    'qualification',
    new EventMetadata('correlation-rollback', null, 'USER', 'manager-001'),
);

try {
    $transactions->transactional(function () use ($pdo, $bus, $rolledBackEvent): void {
        $pdo->exec('INSERT INTO business_changes (id) VALUES (2)');
        $bus->publish($rolledBackEvent);
        throw new RuntimeException('Force rollback.');
    });
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Force rollback.') {
        throw $exception;
    }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM business_changes WHERE id = 2')->fetchColumn() !== 0
    || (int) $pdo->query("SELECT COUNT(*) FROM stored_events WHERE id = 'event-rollback'")->fetchColumn() !== 0
) {
    throw new RuntimeException('Business operation and event did not roll back atomically.');
}

echo "Transactional Event Bus smoke test passed.\n";
