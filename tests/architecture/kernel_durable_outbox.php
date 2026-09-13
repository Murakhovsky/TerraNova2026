<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

function assertDurableOutbox(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$eventStore = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/Event/MysqlEventStore.php');
foreach ([
    'if (!$this->connection->inTransaction())',
    'INSERT INTO cos_events',
    'INSERT INTO cos_event_outbox',
] as $needle) {
    assertDurableOutbox(str_contains($eventStore, $needle), 'Event-store/outbox atomicity invariant missing: ' . $needle);
}

$outbox = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/Event/MysqlEventOutbox.php');
foreach ([
    'FOR UPDATE SKIP LOCKED',
    "status = 'PROCESSING'",
    'locked_by = :worker',
    'recoverTimedOut',
    "status = 'DEAD'",
    'public function replay',
] as $needle) {
    assertDurableOutbox(str_contains($outbox, $needle), 'Durable outbox delivery invariant missing: ' . $needle);
}

$dispatcher = (string) file_get_contents($root . '/app/Kernel/Event/Service/DurableEventDispatcher.php');
foreach ([
    '$this->consumptions->begin(',
    '$this->consumptions->complete(',
    '$this->consumptions->fail(',
] as $needle) {
    assertDurableOutbox(str_contains($dispatcher, $needle), 'Durable consumer idempotency invariant missing: ' . $needle);
}

$actionService = (string) file_get_contents($root . '/app/Kernel/Action/Service/ActionService.php');
assertDurableOutbox(str_contains($actionService, '$this->transactions->transactional($finish)'),
    'Action finalization, event append and audit must share the transaction boundary when transactions are configured.');
assertDurableOutbox(str_contains($actionService, '$this->events?->append($event)'),
    'Action execution result must emit its durable event inside the finalization boundary.');

echo "COS Kernel durable events/outbox architecture invariant passed.\n";
