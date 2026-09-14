<?php
declare(strict_types=1);

use Domains\Sales\Application\Contract\InboundCaseResolverInterface;
use Domains\Sales\Application\Contract\InboundLeadRepositoryInterface;
use Domains\Sales\Application\UseCase\ReceivePublicLead;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$transaction = new class implements TransactionManagerInterface {
    private bool $active = false;
    public function transactional(callable $operation): mixed { $this->active = true; try { return $operation(); } finally { $this->active = false; } }
    public function isActive(): bool { return $this->active; }
    public function afterCommit(callable $callback): void { $callback(); }
};
$stored = [];
$store = new class($stored) implements EventStoreInterface {
    public function __construct(private array &$stored) {}
    public function append(DomainEvent $event): void { $this->stored[] = $event; }
    public function find(string $eventId): ?DomainEvent { return null; }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array { return []; }
};
$created = [];
$leads = new class($created) implements InboundLeadRepositoryInterface {
    public function __construct(private array &$created) {}
    public function create(string $organizationId, array $lead): int { $this->created[] = [$organizationId, $lead]; return 42; }
};
$attachments = [];
$cases = new class($attachments) implements InboundCaseResolverInterface {
    public function __construct(private array &$attachments) {}
    public function resolvePropertyId(mixed $value): ?int { return (int) $value > 0 ? (int) $value : null; }
    public function resolvePersonAndCase(array $input): array { return ['person_id' => 7, 'client_case_id' => 9]; }
    public function attachRequest(int $caseId, int $requestId): void { $this->attachments[] = [$caseId, $requestId]; }
};

$useCase = new ReceivePublicLead($leads, $cases, new EventBus($store, $transaction), $transaction, 'org-1');
$invalid = $useCase->execute(['name' => ''], '/');
if ($invalid->ok || $invalid->code !== 'contact_required' || $created !== []) throw new RuntimeException('Invalid input was persisted.');

$result = $useCase->execute([
    'name' => 'Test Client', 'phone' => '+380000000000', 'property_id' => 12, 'role' => 'покупець', 'deal_type' => 'купівля',
], '/property/demo');
if (!$result->ok || $result->leadId !== 42) throw new RuntimeException('Valid lead was not accepted.');
if (($created[0][0] ?? null) !== 'org-1' || ($created[0][1]['role'] ?? null) !== 'buyer') throw new RuntimeException('Lead normalization or tenant scope failed.');
if ($attachments !== [[9, 42]]) throw new RuntimeException('Lead was not attached to the client case.');
if (count($stored) !== 1 || $stored[0]->type !== 'sales.lead.created' || $stored[0]->organizationId !== 'org-1') {
    throw new RuntimeException('LeadCreated event was not stored with tenant scope.');
}

echo "Public lead application use case passed.\n";
