<?php
declare(strict_types=1);

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\Contract\SalesActivityRepositoryInterface;
use Domains\Sales\Application\DTO\RecordCompletedCallCommand;
use Domains\Sales\Application\UseCase\AddClientCaseActivity;
use Domains\Sales\Application\UseCase\AddClientCasePropertyMatch;
use Domains\Sales\Application\UseCase\AttachInboundRequest;
use Domains\Sales\Application\UseCase\CompleteSalesCall;
use Domains\Sales\Application\UseCase\CreateClientCase;
use Domains\Sales\Application\UseCase\CreateClientCaseFromInboundRequest;
use Domains\Sales\Application\UseCase\EnsureInboundClientCase;
use Domains\Sales\Application\UseCase\QuickUpdateClientCase;
use Domains\Sales\Application\UseCase\RegisterInboundClientCaseRequest;
use Domains\Sales\Application\UseCase\ResolveInboundProperty;
use Domains\Sales\Application\UseCase\UpdateClientCase;
use Domains\Sales\Application\UseCase\UpdateClientCasePropertyMatch;
use Domains\Sales\Application\UseCase\UpdateInboundClientCaseRequest;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

function ensure(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$transactions = new class implements TransactionManagerInterface {
    private bool $active = false;
    public function transactional(callable $operation): mixed
    {
        $wasActive = $this->active;
        $this->active = true;
        try { return $operation(); } finally { $this->active = $wasActive; }
    }
    public function isActive(): bool { return $this->active; }
    public function afterCommit(callable $callback): void { $callback(); }
};

$events = [];
$store = new class($events) implements EventStoreInterface {
    public function __construct(private array &$events) {}
    public function append(DomainEvent $event): void { $this->events[] = $event; }
    public function find(string $eventId): ?DomainEvent { return null; }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array { return []; }
};
$eventBus = new EventBus($store, $transactions);

$readModel = new class implements ClientCaseReadModelInterface {
    public function filters(array $query): array { return []; }
    public function cases(array $filters): array { return []; }
    public function stats(): array { return []; }
    public function case(int $id): ?array
    {
        return $id === 10 ? [
            'id' => 10, 'organization_id' => 'org-1', 'person_id' => 20, 'full_name' => 'Existing Person',
            'phone' => '+380000000000', 'email' => 'old@example.test', 'telegram' => null, 'person_notes' => null,
            'type' => 'buy', 'title' => 'Existing case', 'status' => 'active', 'stage' => 'new', 'priority' => 'normal',
            'assigned_user_id' => null, 'property_type_id' => null, 'location_id' => null, 'source' => 'manual',
            'budget_min' => null, 'budget_max' => null, 'currency' => 'USD', 'area_min' => null, 'area_max' => null,
            'description' => null, 'next_contact_at' => null, 'closed_at' => null,
        ] : null;
    }
    public function inboundRequests(int $caseId): array { return []; }
    public function activities(int $caseId): array { return []; }
    public function propertyMatches(int $caseId): array { return []; }
    public function requestMatches(int $caseId): array { return []; }
    public function unlinkedInboundRequests(): array { return []; }
    public function inboundFilters(array $query): array { return []; }
    public function inboundInbox(array $filters): array { return []; }
    public function inboundInboxStats(): array { return []; }
    public function leadActivities(int $leadId): array { return []; }
    public function openCaseOptions(): array { return []; }
    public function managerOptions(): array { return []; }
};

$repository = new class implements ClientCaseCommandRepositoryInterface {
    public array $writes = [];
    private int $nextPersonId = 100;
    private int $nextCaseId = 200;
    private array $inbound = [
        40 => ['id' => 40, 'full_name' => 'Attach Lead', 'phone' => '+3801', 'email' => null, 'status' => 'new', 'client_case_id' => null, 'assigned_user_id' => null],
        41 => ['id' => 41, 'full_name' => 'Linked Lead', 'phone' => '+3802', 'email' => null, 'status' => 'new', 'client_case_id' => 10, 'assigned_user_id' => null],
        42 => ['id' => 42, 'full_name' => 'Create Lead', 'phone' => '+3803', 'email' => null, 'status' => 'new', 'client_case_id' => null, 'assigned_user_id' => null,
            'property_id' => 50, 'property_type_id' => 7, 'location_id' => 8, 'source_page' => '/listing', 'message' => 'Interested'],
    ];

    public function activeManagerId(string $organizationId, mixed $value): ?int { return (int) $value === 5 ? 5 : null; }
    public function activePropertyTypeId(mixed $value): ?int { return (int) $value === 7 ? 7 : null; }
    public function activeLocationId(mixed $value): ?int { return (int) $value === 8 ? 8 : null; }
    public function property(int $propertyId, bool $activeOnly = false): ?array
    {
        if ($propertyId === 50) return ['id' => 50, 'public_id' => 'PR-50', 'title' => 'Property', 'status' => 'published'];
        if ($propertyId === 51 && !$activeOnly) return ['id' => 51, 'public_id' => 'PR-51', 'title' => 'Draft', 'status' => 'draft'];
        return null;
    }
    public function propertyMatch(string $organizationId, int $matchId): ?array
    {
        return $matchId === 60 ? ['id' => 60, 'client_case_id' => 10, 'property_id' => 50, 'public_id' => 'PR-50', 'title' => 'Property'] : null;
    }
    public function inboundRequest(string $organizationId, int $requestId): ?array { return $this->inbound[$requestId] ?? null; }
    public function findPerson(string $organizationId, ?string $email, ?string $phone): ?array { return null; }
    public function createPerson(string $organizationId, array $person): int
    {
        $id = ++$this->nextPersonId; $this->writes[] = ['create_person', $organizationId, $id, $person]; return $id;
    }
    public function refreshPerson(string $organizationId, int $personId, array $person): bool
    { $this->writes[] = ['refresh_person', $organizationId, $personId, $person]; return true; }
    public function updatePerson(string $organizationId, int $personId, array $person): bool
    { $this->writes[] = ['update_person', $organizationId, $personId, $person]; return $personId === 20; }
    public function createCase(string $organizationId, int $personId, array $case): int
    { $id = ++$this->nextCaseId; $this->writes[] = ['create_case', $organizationId, $id, $personId, $case]; return $id; }
    public function updateCase(string $organizationId, int $caseId, array $case): bool
    { $this->writes[] = ['update_case', $organizationId, $caseId, $case]; return $caseId === 10; }
    public function quickUpdate(string $organizationId, int $caseId, array $changes): bool
    { $this->writes[] = ['quick', $organizationId, $caseId, $changes]; return $caseId === 10; }
    public function addActivity(string $organizationId, int $caseId, int $personId, ?int $userId, array $activity): int
    { $this->writes[] = ['activity', $organizationId, $caseId, $personId, $userId, $activity]; return count($this->writes) + 1000; }
    public function clearNextContact(string $organizationId, int $caseId): void
    { $this->writes[] = ['clear', $organizationId, $caseId]; }
    public function updateInboundRequest(string $organizationId, int $requestId, array $changes): bool
    { $this->writes[] = ['update_inbound', $organizationId, $requestId, $changes]; return isset($this->inbound[$requestId]); }
    public function addLeadActivity(string $organizationId, int $requestId, ?int $userId, array $activity): int
    { $this->writes[] = ['lead_activity', $organizationId, $requestId, $userId, $activity]; return count($this->writes) + 2000; }
    public function syncCaseFromLead(string $organizationId, int $caseId, array $changes): bool
    { $this->writes[] = ['sync_case', $organizationId, $caseId, $changes]; return $caseId === 10; }
    public function attachInboundRequest(string $organizationId, int $caseId, int $personId, int $requestId, ?int $userId): bool
    {
        $this->writes[] = ['attach', $organizationId, $caseId, $personId, $requestId, $userId];
        if (!isset($this->inbound[$requestId])) return false;
        $this->inbound[$requestId]['client_case_id'] = $caseId;
        return true;
    }
    public function registerInboundRequest(string $organizationId, int $caseId, int $requestId): bool
    { $this->writes[] = ['register', $organizationId, $caseId, $requestId]; return isset($this->inbound[$requestId]); }
    public function upsertPropertyMatch(string $organizationId, int $caseId, int $propertyId, array $match): bool
    { $this->writes[] = ['upsert_match', $organizationId, $caseId, $propertyId, $match]; return true; }
    public function updatePropertyMatch(string $organizationId, int $matchId, array $match): bool
    { $this->writes[] = ['update_match', $organizationId, $matchId, $match]; return $matchId === 60; }
};

$salesActivities = new class implements SalesActivityRepositoryInterface {
    public function recordCompletedCall(RecordCompletedCallCommand $command): string { return 'call-1'; }
};
$completeCall = new CompleteSalesCall($salesActivities, $eventBus, $transactions);
$organizationId = 'org-1';
$user = ['id' => 5];

$create = new CreateClientCase($repository, $eventBus, $transactions, $organizationId);
ensure(!$create->execute(['full_name' => 'Missing contact'])->ok, 'Create accepted a case without contact data.');
$created = $create->execute(['full_name' => 'New Person', 'phone' => '+3804', 'type' => 'buy'], $user);
ensure($created->ok && ($created->data['case_id'] ?? null) === 201, 'Create client case failed.');

$update = new UpdateClientCase($readModel, $repository, $eventBus, $transactions, $organizationId);
ensure($update->execute(10, ['full_name' => 'Updated Person', 'stage' => 'qualification'], $user)->ok, 'Update client case failed.');

$quick = new QuickUpdateClientCase($readModel, $repository, $eventBus, $transactions, $organizationId);
ensure($quick->execute(10, ['stage' => 'qualification', 'priority' => 'urgent', 'assigned_user_id' => 5], $user)->ok, 'Quick update failed.');

$activity = new AddClientCaseActivity($readModel, $repository, $completeCall, $transactions, $organizationId);
ensure($activity->execute(10, ['title' => 'Note'], $user)->ok, 'Add activity failed.');
ensure($activity->execute(10, ['activity_type' => 'call', 'completed' => 1, 'title' => 'Call'], $user)->ok, 'Completed call activity failed.');

$attach = new AttachInboundRequest($readModel, $repository, $eventBus, $transactions, $organizationId);
ensure($attach->execute(10, 40, $user)->ok, 'Attach inbound request failed.');
ensure(!$attach->execute(10, 999, $user)->ok, 'Unknown inbound request was accepted.');

$updateInbound = new UpdateInboundClientCaseRequest($readModel, $repository, $eventBus, $transactions, $organizationId);
ensure($updateInbound->execute(41, ['status' => 'viewing', 'completed' => 1], $user)->ok, 'Update inbound request failed.');

$createFromInbound = new CreateClientCaseFromInboundRequest($repository, $eventBus, $transactions, $organizationId);
$fromInbound = $createFromInbound->execute(42, ['assigned_user_id' => 5], $user);
ensure($fromInbound->ok && ($fromInbound->data['case_id'] ?? null) === 202, 'Create case from inbound request failed.');

$addMatch = new AddClientCasePropertyMatch($readModel, $repository, $transactions, $organizationId);
ensure($addMatch->execute(10, 50, ['match_status' => 'interested'], $user)->ok, 'Add property match failed.');
$updateMatch = new UpdateClientCasePropertyMatch($readModel, $repository, $transactions, $organizationId);
ensure($updateMatch->execute(60, ['match_status' => 'viewing'], $user)->ok, 'Update property match failed.');

$ensureInbound = new EnsureInboundClientCase($repository, $eventBus, $transactions, $organizationId);
$ensured = $ensureInbound->execute(['name' => 'Public Lead', 'phone' => '+3805', 'property_id' => 50]);
ensure($ensured->ok && ($ensured->data['client_case_id'] ?? null) === 203, 'Ensure inbound case failed.');

$resolveProperty = new ResolveInboundProperty($repository);
ensure($resolveProperty->execute(50) === 50 && $resolveProperty->execute(51) === null, 'Inbound property validation failed.');
(new RegisterInboundClientCaseRequest($repository, $transactions, $organizationId))->execute(10, 41);

foreach ($repository->writes as $write) {
    ensure(($write[1] ?? null) === $organizationId, 'A command write lost tenant scope: ' . json_encode($write));
}
$eventTypes = array_map(static fn(DomainEvent $event): string => $event->type, $events);
foreach (['sales.client_case.created', 'sales.client_case.changed', 'sales.deal.stage_changed', 'sales.lead.changed', 'sales.call.completed'] as $type) {
    ensure(in_array($type, $eventTypes, true), 'Expected event was not published: ' . $type);
}

echo "Complete client-case command migration passed.\n";
