<?php
declare(strict_types=1);

use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Application\DTO\StartDiagnosticSessionCommand;
use Domains\Diagnostic\Application\UseCase\CaptureDiagnosticEvidence;
use Domains\Diagnostic\Application\UseCase\CompleteDiagnosticSession;
use Domains\Diagnostic\Application\UseCase\EvaluateDiagnosticSession;
use Domains\Diagnostic\Application\UseCase\PublishDiagnosticPack;
use Domains\Diagnostic\Application\UseCase\RecordDiagnosticResult;
use Domains\Diagnostic\Application\UseCase\StartDiagnosticSession;
use Domains\Diagnostic\Methodology\Loader\PackLoader;
use Domains\Diagnostic\Methodology\Serialization\MethodologyPackSerializer;
use Domains\Diagnostic\Model\DiagnosticPack;
use Domains\Diagnostic\Model\DiagnosticPackStatus;
use Domains\Diagnostic\Model\DiagnosticRecord;
use Domains\Diagnostic\Model\DiagnosticRecordType;
use Domains\Diagnostic\Model\DiagnosticSession;
use Domains\Diagnostic\Model\DiagnosticSessionStatus;
use Domains\Diagnostic\Model\DiagnosticTarget;
use Domains\Diagnostic\Model\Evidence;
use Domains\Diagnostic\Model\EvidenceType;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

function diagnosticEnsure(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function diagnosticThrows(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException|InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
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

$publishedEvents = [];
$eventStore = new class($publishedEvents) implements EventStoreInterface {
    public function __construct(private array &$events) {}
    public function append(DomainEvent $event): void { $this->events[] = $event; }
    public function find(string $eventId): ?DomainEvent { return null; }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array { return []; }
};
$eventBus = new EventBus($eventStore, $transactions);

$packRepository = new class implements DiagnosticPackRepositoryInterface {
    /** @var array<string, DiagnosticPack> */
    private array $packs = [];
    public function save(string $organizationId, DiagnosticPack $pack, ?int $expectedLockVersion = null): void
    {
        $this->packs[$organizationId . ':' . $pack->id() . ':' . $pack->version()] = $pack;
    }
    public function get(string $organizationId, string $packId, int $version): ?DiagnosticPack
    {
        return $this->packs[$organizationId . ':' . $packId . ':' . $version] ?? null;
    }
};

$sessionRepository = new class implements DiagnosticSessionRepositoryInterface {
    /** @var array<string, DiagnosticSession> */
    private array $sessions = [];
    public function save(string $organizationId, DiagnosticSession $session, ?int $expectedLockVersion = null): void
    {
        $this->sessions[$organizationId . ':' . $session->id()] = $session;
    }
    public function get(string $organizationId, string $sessionId): ?DiagnosticSession
    {
        return $this->sessions[$organizationId . ':' . $sessionId] ?? null;
    }
};

$organizationId = 'org-1';
$now = new DateTimeImmutable('2026-08-30T10:00:00+03:00');
$methodology = (new PackLoader())->load((string) file_get_contents($root . '/tests/fixtures/diagnostic/sales-methodology.json'));
$pack = DiagnosticPack::draft('Sales', $methodology);
$packRepository->save($organizationId, $pack);

(new PublishDiagnosticPack($packRepository, $eventBus, $transactions))->execute(
    $organizationId,
    $pack->id(),
    $pack->version(),
    $now,
    'USER',
    '7',
);
diagnosticEnsure($pack->status() === DiagnosticPackStatus::Published, 'Pack was not published.');
$revisionData = (new MethodologyPackSerializer())->toArray($methodology);
$revisionData['version'] = 2;
$revision = $pack->revise((new PackLoader())->load($revisionData));
diagnosticEnsure($revision->version() === 2 && $revision->status() === DiagnosticPackStatus::Draft, 'Pack revision is not a new draft version.');

$start = new StartDiagnosticSession($packRepository, $sessionRepository, $eventBus, $transactions);
$session = $start->execute(
    new StartDiagnosticSessionCommand(
        $organizationId,
        'session-1',
        $pack->id(),
        $pack->version(),
        new DiagnosticTarget('Sales', 'organization', 'company-42'),
    ),
    $now,
    'USER',
    '7',
);
diagnosticEnsure($session->status() === DiagnosticSessionStatus::InProgress, 'Session did not start.');
diagnosticEnsure($session->packVersion() === 1, 'Session did not pin the published pack version.');

diagnosticThrows(
    static fn () => $start->execute(
        new StartDiagnosticSessionCommand(
            $organizationId,
            'wrong-target',
            $pack->id(),
            $pack->version(),
            new DiagnosticTarget('Finance', 'organization', 'company-42'),
        ),
        $now,
    ),
    'A Sales pack accepted a Finance target.',
);

$capture = new CaptureDiagnosticEvidence($sessionRepository, $eventBus, $transactions);
$capture->execute($organizationId, $session->id(), new Evidence(
    'evidence-crm-export',
    EvidenceType::SystemData,
    'CRM funnel export',
    'crm://exports/2026-08',
    $now,
    ['rows' => 1280],
));

$record = new RecordDiagnosticResult($packRepository, $sessionRepository, $eventBus, $transactions);
diagnosticThrows(
    static fn () => $record->execute($organizationId, $session->id(), new DiagnosticRecord(
        'metric-bad',
        DiagnosticRecordType::Metric,
        'pipeline.conversion',
        'Qualified-to-won conversion',
        0.12,
        'ratio',
        ['missing-evidence'],
        [],
        $now,
    )),
    'A record accepted a dangling evidence reference.',
);

$record->execute($organizationId, $session->id(), new DiagnosticRecord(
    'metric-response-time',
    DiagnosticRecordType::Metric,
    'lead_response_time',
    'Lead response time is 75 minutes.',
    75,
    'minutes',
    ['evidence-crm-export'],
    [],
    $now,
));
$record->execute($organizationId, $session->id(), new DiagnosticRecord(
    'metric-lost-rate',
    DiagnosticRecordType::Metric,
    'lost_lead_rate',
    'Lost lead rate is 25%.',
    25,
    'percent',
    ['evidence-crm-export'],
    [],
    $now,
));
$record->execute($organizationId, $session->id(), new DiagnosticRecord(
    'metric-lead-count',
    DiagnosticRecordType::Metric,
    'lead_count',
    'Lead count is 120.',
    120,
    'count',
    ['evidence-crm-export'],
    [],
    $now,
));

$evaluation = (new EvaluateDiagnosticSession($packRepository, $sessionRepository, $eventBus, $transactions))
    ->execute($organizationId, $session->id(), $now->modify('+1 hour'), 'USER', '7');
diagnosticEnsure($evaluation->score === 20.0, 'Session evaluation did not use the pinned methodology.');
diagnosticEnsure($session->hasRecord('assessment:lead-processing'), 'Evaluation did not persist an assessment.');
diagnosticEnsure($session->hasRecord('finding:slow-lead-processing'), 'Evaluation did not persist a finding.');

$record->execute($organizationId, $session->id(), new DiagnosticRecord(
    'recommendation-qualification',
    DiagnosticRecordType::Recommendation,
    'lead-processing',
    'Standardize lead qualification before pipeline entry.',
    null,
    null,
    [],
    ['finding:slow-lead-processing'],
    $now,
));

$traceability = $session->traceability();
diagnosticEnsure(
    ($traceability['metric-response-time']['evidence'] ?? []) === ['evidence-crm-export'],
    'Metric-to-evidence traceability was lost.',
);
diagnosticEnsure(
    ($traceability['recommendation-qualification']['upstream_records'] ?? []) === ['finding:slow-lead-processing'],
    'Recommendation-to-finding traceability was lost.',
);

(new CompleteDiagnosticSession($packRepository, $sessionRepository, $eventBus, $transactions))->execute(
    $organizationId,
    $session->id(),
    $now->modify('+2 hours'),
    'USER',
    '7',
);
diagnosticEnsure($session->status() === DiagnosticSessionStatus::Completed, 'Session was not completed.');
diagnosticThrows(
    static fn () => $session->capture(new Evidence(
        'late-evidence', EvidenceType::Observation, 'Late evidence', 'manual', $now,
    )),
    'A completed session accepted new evidence.',
);

$eventTypes = array_map(static fn (DomainEvent $event): string => $event->type, $publishedEvents);
foreach ([
    'diagnostic.pack.published',
    'diagnostic.session.started',
    'diagnostic.evidence.captured',
    'diagnostic.record.added',
    'diagnostic.session.evaluated',
    'diagnostic.session.completed',
] as $eventType) {
    diagnosticEnsure(in_array($eventType, $eventTypes, true), 'Expected event was not published: ' . $eventType);
}

echo "Diagnostic Domain lifecycle and traceability passed.\n";
