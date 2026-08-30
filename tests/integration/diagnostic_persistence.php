<?php
declare(strict_types=1);

use Domains\Diagnostic\Application\DTO\StartDiagnosticSessionCommand;
use Domains\Diagnostic\Application\UseCase\CaptureDiagnosticEvidence;
use Domains\Diagnostic\Application\UseCase\CompleteDiagnosticSession;
use Domains\Diagnostic\Application\UseCase\DraftDiagnosticPack;
use Domains\Diagnostic\Application\UseCase\EvaluateDiagnosticSession;
use Domains\Diagnostic\Application\UseCase\PublishDiagnosticPack;
use Domains\Diagnostic\Application\UseCase\RecordDiagnosticResult;
use Domains\Diagnostic\Application\UseCase\StartDiagnosticSession;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlDiagnosticPackRepository;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlDiagnosticSessionRepository;
use Domains\Diagnostic\Methodology\Loader\PackLoader;
use Domains\Diagnostic\Methodology\Serialization\MethodologyPackSerializer;
use Domains\Diagnostic\Model\DiagnosticConcurrencyException;
use Domains\Diagnostic\Model\DiagnosticRecord;
use Domains\Diagnostic\Model\DiagnosticRecordType;
use Domains\Diagnostic\Model\DiagnosticSessionStatus;
use Domains\Diagnostic\Model\DiagnosticTarget;
use Domains\Diagnostic\Model\Evidence;
use Domains\Diagnostic\Model\EvidenceType;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager;
use Kernel\Event\EventBus;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

function diagnosticPersistenceEnsure(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        (int) ($_ENV['DB_PORT'] ?? 3306),
        $_ENV['DB_DATABASE'] ?? 'cos',
    ),
    $_ENV['DB_USERNAME'] ?? 'cos',
    $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$migration = $pdo->query("SELECT COUNT(*) FROM tn_migrations WHERE migration = '20260830_000020_diagnostic_domain'")->fetchColumn();
diagnosticPersistenceEnsure((int) $migration === 1, 'Diagnostic migration is not applied.');

$transactions = new TransactionManager($pdo);
$eventBus = new EventBus(new MysqlEventStore($pdo), $transactions);
$packs = new MysqlDiagnosticPackRepository($pdo);
$sessions = new MysqlDiagnosticSessionRepository($pdo);
$organizationId = 'default';
$suffix = bin2hex(random_bytes(6));
$packId = 'sales-integration-' . $suffix;
$sessionId = 'diagnostic-integration-' . $suffix;
$now = new DateTimeImmutable('2026-08-30T12:00:00+03:00');

$methodologyData = json_decode(
    (string) file_get_contents($root . '/tests/fixtures/diagnostic/sales-methodology.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$methodologyData['id'] = $packId;
$methodologyData['name'] = 'Sales Integration ' . $suffix;
$methodology = (new PackLoader())->load($methodologyData);

$pdo->beginTransaction();
try {
    $pack = (new DraftDiagnosticPack($packs, $eventBus, $transactions))->execute(
        $organizationId, 'Sales', $methodology, $now, 'SYSTEM', 'integration-test',
    );
    (new PublishDiagnosticPack($packs, $eventBus, $transactions))->execute(
        $organizationId, $packId, 1, $now, 'SYSTEM', 'integration-test',
    );
    $storedPack = $packs->get($organizationId, $packId, 1);
    diagnosticPersistenceEnsure($storedPack !== null && $storedPack->contentHash() === (new MethodologyPackSerializer())->hash($methodology), 'Pack round-trip or content hash failed.');

    $session = (new StartDiagnosticSession($packs, $sessions, $eventBus, $transactions))->execute(
        new StartDiagnosticSessionCommand(
            $organizationId, $sessionId, $packId, 1,
            new DiagnosticTarget('Sales', 'organization', 'company-integration'),
        ),
        $now,
        'SYSTEM',
        'integration-test',
    );

    $firstCopy = $sessions->get($organizationId, $sessionId);
    $staleCopy = $sessions->get($organizationId, $sessionId);
    diagnosticPersistenceEnsure($firstCopy !== null && $staleCopy !== null, 'Session did not round-trip.');
    $firstVersion = $firstCopy->lockVersion();
    $firstCopy->capture(new Evidence('concurrency-a', EvidenceType::Observation, 'Concurrency A', 'integration', $now));
    $sessions->save($organizationId, $firstCopy, $firstVersion);
    $staleRejected = false;
    try {
        $staleCopy->capture(new Evidence('concurrency-b', EvidenceType::Observation, 'Concurrency B', 'integration', $now));
        $sessions->save($organizationId, $staleCopy, $firstVersion);
    } catch (DiagnosticConcurrencyException) {
        $staleRejected = true;
    }
    diagnosticPersistenceEnsure($staleRejected, 'A stale diagnostic session write was accepted.');

    $capture = new CaptureDiagnosticEvidence($sessions, $eventBus, $transactions);
    $capture->execute($organizationId, $sessionId, new Evidence(
        'crm-export', EvidenceType::SystemData, 'CRM export', 'crm://integration', $now,
        ['confidence' => 0.95, 'quality' => 1.0],
    ));
    $record = new RecordDiagnosticResult($packs, $sessions, $eventBus, $transactions);
    foreach ([
        ['response-time', 'lead_response_time', 75, 'minutes'],
        ['lost-rate', 'lost_lead_rate', 25, 'percent'],
        ['lead-count', 'lead_count', 120, 'count'],
    ] as [$id, $code, $value, $unit]) {
        $record->execute($organizationId, $sessionId, new DiagnosticRecord(
            $id, DiagnosticRecordType::Metric, $code, $code . ' observed', $value, $unit,
            ['crm-export'], [], $now,
        ));
    }

    $result = (new EvaluateDiagnosticSession($packs, $sessions, $eventBus, $transactions))->execute(
        $organizationId, $sessionId, $now->modify('+1 hour'), 'SYSTEM', 'integration-test',
    );
    diagnosticPersistenceEnsure($result->score === 20.0, 'Persisted session evaluation returned the wrong score.');
    $evaluated = $sessions->get($organizationId, $sessionId);
    diagnosticPersistenceEnsure(
        $evaluated !== null
        && $evaluated->hasRecord('assessment:lead-processing')
        && $evaluated->hasRecord('finding:slow-lead-processing'),
        'Evaluated records did not survive persistence round-trip.',
    );
    $findingTrace = $evaluated->traceability()['finding:slow-lead-processing'] ?? null;
    diagnosticPersistenceEnsure(
        is_array($findingTrace)
        && $findingTrace['evidence'] === ['crm-export']
        && $findingTrace['upstream_records'] === ['assessment:lead-processing'],
        'Persisted finding traceability is incomplete.',
    );

    (new CompleteDiagnosticSession($packs, $sessions, $eventBus, $transactions))->execute(
        $organizationId, $sessionId, $now->modify('+2 hours'), 'SYSTEM', 'integration-test',
    );
    diagnosticPersistenceEnsure(
        $sessions->get($organizationId, $sessionId)?->status() === DiagnosticSessionStatus::Completed,
        'Completed session did not survive persistence round-trip.',
    );

    $eventCount = $pdo->prepare(
        "SELECT COUNT(*) FROM cos_events WHERE organization_id = :organization_id AND aggregate_id IN (:pack_aggregate, :session_id) AND type LIKE 'diagnostic.%'"
    );
    $eventCount->execute([
        'organization_id' => $organizationId,
        'pack_aggregate' => $packId . ':1',
        'session_id' => $sessionId,
    ]);
    $persistedEventCount = (int) $eventCount->fetchColumn();
    diagnosticPersistenceEnsure($persistedEventCount >= 8, 'Diagnostic state changes did not persist their Domain Events.');
    $outboxCount = $pdo->prepare(
        "SELECT COUNT(*) FROM cos_event_outbox o INNER JOIN cos_events e ON e.id = o.event_id WHERE e.organization_id = :organization_id AND e.aggregate_id IN (:pack_aggregate, :session_id) AND e.type LIKE 'diagnostic.%'"
    );
    $outboxCount->execute([
        'organization_id' => $organizationId,
        'pack_aggregate' => $packId . ':1',
        'session_id' => $sessionId,
    ]);
    diagnosticPersistenceEnsure((int) $outboxCount->fetchColumn() === $persistedEventCount, 'Diagnostic Event and Outbox counts diverged.');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo "Diagnostic MySQL persistence passed: tenant scope, hashes, optimistic locking, traceability and atomic Event/Outbox.\n";
