<?php
declare(strict_types=1);

use Domains\Sales\Model\DealChangeSet;
use Domains\Sales\Application\DTO\CrmInboxItem;
use Domains\Sales\Application\DTO\RecordCompletedCallCommand;
use Domains\Sales\Application\UseCase\CompleteSalesCall;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager;
use Infrastructure\Integration\Crm\MysqlCrmInboundApplier;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlDealRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesActivityRepository;
use Kernel\Event\EventBus;

$root = dirname(__DIR__, 2);
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    if (getenv($key) !== false) continue;
    $value = trim($value, "\"'");
    putenv($key . '=' . $value);
}
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Kernel\\' => '/app/Kernel/', 'Domains\\' => '/app/Domains/', 'Infrastructure\\' => '/app/Infrastructure/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', (int) (getenv('DB_PORT') ?: 3306), getenv('DB_DATABASE') ?: 'cos'),
    getenv('DB_USERNAME') ?: 'cos', getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$requiredMigrations = [
    '20260826_000017_production_hardening',
    '20260826_000018_remove_obsolete_cos_policies',
    '20260826_000019_tenant_public_identifiers',
];
$statement = $pdo->prepare('SELECT COUNT(*) FROM tn_migrations WHERE migration IN (' . implode(',', array_fill(0, count($requiredMigrations), '?')) . ')');
$statement->execute($requiredMigrations);
if ((int) $statement->fetchColumn() !== count($requiredMigrations)) throw new RuntimeException('Production hardening migrations are not applied.');

$requiredTables = ['cos_organizations', 'cos_organization_memberships', 'cos_crm_inbox', 'cos_configuration_provisions', 'cos_operational_metrics'];
$placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
$tables = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')');
$tables->execute($requiredTables);
if ((int) $tables->fetchColumn() !== count($requiredTables)) throw new RuntimeException('Production tables are incomplete.');

$tenantColumns = ['tn_users', 'tn_people', 'tn_client_cases', 'tn_leads', 'tn_client_case_activities'];
$placeholders = implode(',', array_fill(0, count($tenantColumns), '?'));
$columns = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'organization_id' AND table_name IN ($placeholders)");
$columns->execute($tenantColumns);
if ((int) $columns->fetchColumn() !== count($tenantColumns)) throw new RuntimeException('Tenant columns are incomplete.');

$tenantUniqueIndexes = $pdo->query(
    "SELECT COUNT(DISTINCT table_name) FROM information_schema.statistics "
    . "WHERE table_schema = DATABASE() AND index_name IN ('uq_tn_people_org_public_id','uq_tn_client_cases_org_public_id') "
    . "AND non_unique = 0"
)->fetchColumn();
if ((int) $tenantUniqueIndexes !== 2) throw new RuntimeException('Tenant-scoped public identifier indexes are incomplete.');

$activeObsolete = (int) $pdo->query("SELECT COUNT(*) FROM cos_policies WHERE id IN ('policy-discount-small-auto-v1','policy-discount-large-approval-v1','policy-ai-delete-denied-v1') AND status = 'ACTIVE'")->fetchColumn();
if ($activeObsolete !== 0) throw new RuntimeException('Obsolete policies remain active.');

$case = $pdo->query("SELECT id, organization_id, person_id, priority FROM tn_client_cases ORDER BY id LIMIT 1")->fetch();
if (is_array($case)) {
    $repository = new MysqlDealRepository($pdo);
    $foreign = $repository->update('tenant-that-does-not-own-the-deal', (string) $case['id'], DealChangeSet::fromArray(['priority' => $case['priority']]));
    $owner = $repository->update((string) $case['organization_id'], (string) $case['id'], DealChangeSet::fromArray(['priority' => $case['priority']]));
    if ($foreign->successful || !$owner->successful) throw new RuntimeException('Deal repository tenant boundary failed.');

    $pdo->beginTransaction();
    try {
        $mapped = (new MysqlCrmInboundApplier($pdo))->apply(new CrmInboxItem(
            'inbox-test', (string) $case['organization_id'], 'aida', 'event-test', 'deal.updated',
            ['entity_type' => 'deal', 'external_id' => (string) $case['id'], 'changes' => ['priority' => $case['priority']]],
            1, 'correlation-test',
        ));
        if (($mapped['event_type'] ?? null) !== 'sales.client_case.changed') {
            throw new RuntimeException('External CRM event was not mapped to a Sales-owned event.');
        }
    } finally {
        $pdo->rollBack();
    }

    $eventId = bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try {
        $transactions = new TransactionManager($pdo);
        $activityId = (new CompleteSalesCall(
            new MysqlSalesActivityRepository($pdo),
            new EventBus(new MysqlEventStore($pdo), $transactions),
            $transactions,
        ))->execute(new RecordCompletedCallCommand(
            (string) $case['organization_id'], (string) $case['id'], (string) $case['person_id'], null,
            'Integration test call', null, 60, 'completed', $eventId, $eventId, 'SYSTEM', 'test-suite',
        ));
        $eventCount = $pdo->prepare('SELECT COUNT(*) FROM cos_events WHERE id = :id');
        $eventCount->execute(['id' => $eventId]);
        $outboxCount = $pdo->prepare('SELECT COUNT(*) FROM cos_event_outbox WHERE event_id = :id');
        $outboxCount->execute(['id' => $eventId]);
        $activityCount = $pdo->prepare('SELECT COUNT(*) FROM tn_client_case_activities WHERE id = :id AND organization_id = :organization_id');
        $activityCount->execute(['id' => $activityId, 'organization_id' => $case['organization_id']]);
        if ((int) $eventCount->fetchColumn() !== 1 || (int) $outboxCount->fetchColumn() !== 1 || (int) $activityCount->fetchColumn() !== 1) {
            throw new RuntimeException('Completed-call transaction did not contain business state, Event, and Outbox.');
        }
    } finally {
        $pdo->rollBack();
    }
    $rolledBack = $pdo->prepare('SELECT COUNT(*) FROM cos_events WHERE id = :id');
    $rolledBack->execute(['id' => $eventId]);
    if ((int) $rolledBack->fetchColumn() !== 0) throw new RuntimeException('Completed-call transaction did not roll back atomically.');
}

echo "MySQL schema, migrations, tenant isolation, CRM mapping and atomic Event/Outbox persistence passed.\n";
