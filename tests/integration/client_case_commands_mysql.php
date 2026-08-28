<?php
declare(strict_types=1);

use Infrastructure\Persistence\MySql\Sales\MysqlClientCaseCommandRepository;
use Infrastructure\Persistence\MySql\Sales\MysqlInboundLeadRepository;
use Infrastructure\ReadModel\MySql\MysqlClientCaseReadModel;
use Infrastructure\Database\Event\MysqlEventStore;
use Infrastructure\Database\Transaction\TransactionManager;
use Domains\Sales\Application\UseCase\CreateClientCase;
use Domains\Sales\Application\UseCase\QuickUpdateClientCase;
use Domains\Sales\Application\UseCase\UpdateClientCase;
use Kernel\Event\EventBus;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    if (getenv($key) === false) putenv($key . '=' . trim($value, "\"'"));
}

function assertCommand(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$connection = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', (int) (getenv('DB_PORT') ?: 3306), getenv('DB_DATABASE') ?: 'cos'),
    getenv('DB_USERNAME') ?: 'cos',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$organizationId = (string) $connection->query('SELECT id FROM cos_organizations WHERE status="ACTIVE" ORDER BY id LIMIT 1')->fetchColumn();
assertCommand($organizationId !== '', 'No active organization is available for ClientCase command integration.');
$propertyTypeId = $connection->query('SELECT id FROM tn_property_types WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
$locationId = $connection->query('SELECT id FROM tn_locations WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
$propertyId = $connection->query('SELECT id FROM tn_properties ORDER BY id LIMIT 1')->fetchColumn();
$managerStatement = $connection->prepare('SELECT u.id FROM tn_users u
    INNER JOIN cos_organization_memberships m ON m.user_id=u.id AND m.organization_id=:organization_id
    WHERE u.status="active" AND m.status="ACTIVE" AND m.role IN ("manager","admin") ORDER BY u.id LIMIT 1');
$managerStatement->execute(['organization_id' => $organizationId]);
$managerId = $managerStatement->fetchColumn();

$commands = new MysqlClientCaseCommandRepository($connection);
$leads = new MysqlInboundLeadRepository($connection);
$token = bin2hex(random_bytes(6));
$connection->beginTransaction();
try {
    $transactions = new TransactionManager($connection);
    $eventBus = new EventBus(new MysqlEventStore($connection), $transactions);
    $readModel = new MysqlClientCaseReadModel($connection, $organizationId);
    $createdByUseCase = (new CreateClientCase($commands, $eventBus, $transactions, $organizationId))->execute([
        'full_name' => 'UseCase ' . $token,
        'email' => $token . '-usecase@commands.test',
        'type' => 'buy',
        'title' => 'UseCase integration ' . $token,
    ]);
    $useCaseId = (int) ($createdByUseCase->data['case_id'] ?? 0);
    assertCommand($createdByUseCase->ok && $useCaseId > 0, 'CreateClientCase MySQL flow failed.');
    assertCommand((new UpdateClientCase($readModel, $commands, $eventBus, $transactions, $organizationId))->execute(
        $useCaseId,
        ['full_name' => 'Updated UseCase ' . $token, 'email' => $token . '-usecase@commands.test', 'stage' => 'qualification'],
    )->ok, 'UpdateClientCase MySQL flow failed.');
    assertCommand((new QuickUpdateClientCase($readModel, $commands, $eventBus, $transactions, $organizationId))->execute(
        $useCaseId,
        ['stage' => 'matching', 'priority' => 'high'],
    )->ok, 'QuickUpdateClientCase MySQL flow failed.');
    $eventCount = $connection->prepare('SELECT COUNT(*) FROM cos_events WHERE organization_id=:organization_id AND aggregate_id=:case_id');
    $eventCount->execute(['organization_id' => $organizationId, 'case_id' => (string) $useCaseId]);
    assertCommand((int) $eventCount->fetchColumn() >= 4, 'ClientCase use cases did not persist the expected domain events.');

    $personId = $commands->createPerson($organizationId, [
        'full_name' => 'Command Migration ' . $token,
        'phone' => '+380' . random_int(100000000, 999999999),
        'email' => $token . '@commands.test',
        'telegram' => null,
        'notes' => 'integration',
    ]);
    assertCommand($personId > 0, 'Person creation failed.');
    assertCommand((int) ($commands->findPerson($organizationId, $token . '@commands.test', null)['id'] ?? 0) === $personId, 'Tenant person lookup failed.');
    assertCommand($commands->refreshPerson($organizationId, $personId, [
        'full_name' => 'Refreshed ' . $token, 'phone' => null, 'email' => $token . '@commands.test', 'telegram' => '@integration',
    ]), 'Person refresh failed.');
    assertCommand($commands->updatePerson($organizationId, $personId, [
        'full_name' => 'Updated ' . $token, 'phone' => null, 'email' => $token . '@commands.test',
        'telegram' => '@integration', 'notes' => 'updated',
    ]), 'Person update failed.');

    $caseId = $commands->createCase($organizationId, $personId, [
        'type' => 'buy', 'title' => 'Integration case ' . $token, 'status' => 'active', 'stage' => 'new',
        'priority' => 'normal', 'assigned_user_id' => $managerId !== false ? (int) $managerId : null,
        'source' => 'integration', 'property_type_id' => $propertyTypeId !== false ? (int) $propertyTypeId : null,
        'location_id' => $locationId !== false ? (int) $locationId : null, 'budget_min' => '100000.00',
        'budget_max' => '150000.00', 'currency' => 'USD', 'area_min' => '40.00', 'area_max' => '80.00',
        'description' => 'command integration', 'parameters_json' => null, 'next_contact_at' => null, 'closed_at' => null,
    ]);
    assertCommand($caseId > 0, 'Client case creation failed.');
    assertCommand(!$commands->quickUpdate('foreign-tenant', $caseId, [
        'status' => 'active', 'stage' => 'new', 'priority' => 'normal', 'assigned_user_id' => null,
        'next_contact_at' => null, 'closed_at' => null,
    ]), 'Foreign tenant updated a client case.');
    assertCommand($commands->quickUpdate($organizationId, $caseId, [
        'status' => 'active', 'stage' => 'qualification', 'priority' => 'high', 'assigned_user_id' => null,
        'next_contact_at' => null, 'closed_at' => null,
    ]), 'Quick update failed.');
    assertCommand($commands->updateCase($organizationId, $caseId, [
        'type' => 'buy', 'title' => 'Updated integration case ' . $token, 'status' => 'active', 'stage' => 'matching',
        'priority' => 'high', 'assigned_user_id' => null, 'source' => 'integration',
        'property_type_id' => $propertyTypeId !== false ? (int) $propertyTypeId : null,
        'location_id' => $locationId !== false ? (int) $locationId : null, 'budget_min' => '100000.00',
        'budget_max' => '150000.00', 'currency' => 'USD', 'area_min' => '40.00', 'area_max' => '80.00',
        'description' => 'updated', 'parameters_json' => null, 'next_contact_at' => null, 'closed_at' => null,
    ]), 'Full case update failed.');
    assertCommand($commands->addActivity($organizationId, $caseId, $personId, null, [
        'activity_type' => 'note', 'title' => 'Integration activity', 'body' => null, 'due_at' => null, 'completed_at' => null,
    ]) > 0, 'Client case activity creation failed.');

    $leadId = $leads->create($organizationId, [
        'person_id' => null, 'client_case_id' => null, 'property_id' => $propertyId !== false ? (int) $propertyId : null,
        'full_name' => 'Lead ' . $token, 'phone' => null, 'email' => $token . '@lead.test', 'role' => 'buyer',
        'deal_type' => 'sale', 'message' => 'integration', 'source_page' => '/integration',
    ]);
    assertCommand($commands->inboundRequest($organizationId, $leadId) !== null, 'Inbound request lookup failed.');
    assertCommand($commands->updateInboundRequest($organizationId, $leadId, [
        'status' => 'contacted', 'assigned_user_id' => null, 'manager_note' => 'contacted',
        'last_contacted_at' => date('Y-m-d H:i:s'), 'next_contact_at' => null,
    ]), 'Inbound request update failed.');
    assertCommand($commands->addLeadActivity($organizationId, $leadId, null, [
        'activity_type' => 'status_change', 'title' => 'Contacted', 'body' => null, 'due_at' => null, 'completed_at' => null,
    ]) > 0, 'Lead activity creation failed.');
    assertCommand($commands->attachInboundRequest($organizationId, $caseId, $personId, $leadId, null), 'Inbound attach failed.');
    assertCommand($commands->registerInboundRequest($organizationId, $caseId, $leadId), 'Inbound registration failed.');
    assertCommand($commands->syncCaseFromLead($organizationId, $caseId, [
        'stage' => 'viewing', 'status' => 'active', 'assigned_user_id' => null, 'next_contact_at' => null, 'closed_at' => null,
    ]), 'Case synchronization from lead failed.');

    if ($propertyId !== false) {
        assertCommand($commands->upsertPropertyMatch($organizationId, $caseId, (int) $propertyId, [
            'match_status' => 'interested', 'score' => 80, 'note' => 'integration',
        ]), 'Property match creation failed.');
        $matchId = (int) $connection->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        if ($matchId === 0) {
            $findMatch = $connection->prepare('SELECT id FROM tn_client_case_property_matches WHERE client_case_id=:case_id AND property_id=:property_id');
            $findMatch->execute(['case_id' => $caseId, 'property_id' => (int) $propertyId]);
            $matchId = (int) $findMatch->fetchColumn();
        }
        assertCommand($commands->propertyMatch($organizationId, $matchId) !== null, 'Property match lookup failed.');
        assertCommand($commands->updatePropertyMatch($organizationId, $matchId, [
            'match_status' => 'viewing', 'score' => 90, 'note' => 'updated',
        ]), 'Property match update failed.');
    }
} finally {
    $connection->rollBack();
}

echo "MySQL ClientCase command repository and use cases passed.\n";
