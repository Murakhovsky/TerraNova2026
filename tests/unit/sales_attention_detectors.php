<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Application\Contract\SalesAttentionRepositoryInterface;
use Domains\Sales\Application\Contract\SalesOutcomeRepositoryInterface;
use Domains\Sales\Application\DTO\RecordActionOutcomeCommand;
use Domains\Sales\Application\Service\SalesMonitoringService;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$events = [];
$claims = [];
$attention = new class($claims) implements SalesAttentionRepositoryInterface {
    public function __construct(private array &$claims) {}
    public function inactiveDeals(string $organizationId, DateTimeImmutable $cutoff, int $limit): array { return [['id' => 'deal-1', 'last_activity_at' => '2026-09-01 00:00:00']]; }
    public function missedFollowups(string $organizationId, DateTimeImmutable $now, int $limit): array { return [['id' => 'followup-1', 'client_case_id' => 'deal-1', 'due_at' => '2026-09-05 00:00:00']]; }
    public function claimSignal(string $organizationId, string $type, string $key, string $marker): bool
    {
        $claim = "$organizationId:$type:$key";
        if (isset($this->claims[$claim])) return false;
        $this->claims[$claim] = true;
        return true;
    }
};
$outcomes = new class implements SalesOutcomeRepositoryInterface {
    public function record(RecordActionOutcomeCommand $command): string { return 'outcome-1'; }
};
$tx = new class implements TransactionManagerInterface {
    public function transactional(callable $operation): mixed { return $operation(); }
    public function isActive(): bool { return true; }
    public function afterCommit(callable $callback): void { $callback(); }
};
$store = new class($events) implements EventStoreInterface {
    public function __construct(private array &$events) {}
    public function append(DomainEvent $event): void { $this->events[] = $event; }
    public function find(string $id): ?DomainEvent { return null; }
    public function findByAggregate(string $organizationId, string $type, string $id, int $limit = 100): array { return []; }
};

$service = new SalesMonitoringService($attention, $outcomes, new EventBus($store, $tx), $tx);
$now = new DateTimeImmutable('2026-09-06T12:00:00Z');
if ($service->detectNoActivity('org-1', $now) !== 1 || $service->detectNoActivity('org-1', $now) !== 0) throw new RuntimeException('No-activity monitoring is not idempotent.');
if ($service->detectMissedFollowups('org-1', $now) !== 1 || $service->detectMissedFollowups('org-1', $now) !== 0) throw new RuntimeException('Missed follow-up monitoring is not idempotent.');
$types = array_map(fn(DomainEvent $event) => $event->type, $events);
if ($types !== ['sales.no_activity_detected', 'sales.followup.missed']) throw new RuntimeException('Sales monitoring canonical events are incorrect.');

echo "Sales monitoring producers passed.\n";
