<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Model\DiagnosticConcurrencyException;
use Domains\Diagnostic\Model\DiagnosticRecord;
use Domains\Diagnostic\Model\DiagnosticRecordType;
use Domains\Diagnostic\Model\DiagnosticSession;
use Domains\Diagnostic\Model\DiagnosticSessionStatus;
use Domains\Diagnostic\Model\DiagnosticTarget;
use Domains\Diagnostic\Model\Evidence;
use Domains\Diagnostic\Model\EvidenceType;
use JsonException;
use PDO;
use RuntimeException;

final readonly class MysqlDiagnosticSessionRepository implements DiagnosticSessionRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function save(string $organizationId, DiagnosticSession $session, ?int $expectedLockVersion = null): void
    {
        $values = [
            'organization_id' => $organizationId,
            'session_id' => $session->id(),
            'pack_id' => $session->packId(),
            'pack_version' => $session->packVersion(),
            'target_domain' => $session->target()->domain,
            'target_subject_type' => $session->target()->subjectType,
            'target_subject_id' => $session->target()->subjectId,
            'status' => $session->status()->value,
            'lock_version' => $session->lockVersion(),
            'started_at' => $this->format($session->startedAt()),
            'completed_at' => $this->format($session->completedAt()),
        ];
        if ($expectedLockVersion === null) {
            $statement = $this->connection->prepare(
                'INSERT INTO diagnostic_sessions '
                . '(organization_id, session_id, pack_id, pack_version, target_domain, target_subject_type, '
                . 'target_subject_id, status, lock_version, started_at, completed_at) '
                . 'VALUES (:organization_id, :session_id, :pack_id, :pack_version, :target_domain, :target_subject_type, '
                . ':target_subject_id, :status, :lock_version, :started_at, :completed_at)'
            );
            $statement->execute($values);
        } else {
            $values['expected_lock_version'] = $expectedLockVersion;
            $statement = $this->connection->prepare(
                'UPDATE diagnostic_sessions SET status = :status, lock_version = :lock_version, '
                . 'started_at = :started_at, completed_at = :completed_at '
                . 'WHERE organization_id = :organization_id AND session_id = :session_id '
                . 'AND pack_id = :pack_id AND pack_version = :pack_version '
                . 'AND target_domain = :target_domain AND target_subject_type = :target_subject_type '
                . 'AND target_subject_id = :target_subject_id AND lock_version = :expected_lock_version'
            );
            $statement->execute($values);
            if ($statement->rowCount() !== 1) {
                throw new DiagnosticConcurrencyException('Diagnostic session changed concurrently.');
            }
        }
        $this->insertEvidence($organizationId, $session);
        $this->insertRecords($organizationId, $session);
    }

    public function get(string $organizationId, string $sessionId): ?DiagnosticSession
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM diagnostic_sessions WHERE organization_id = :organization_id AND session_id = :session_id LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'session_id' => $sessionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        return DiagnosticSession::rehydrate(
            (string) $row['session_id'],
            (string) $row['pack_id'],
            (int) $row['pack_version'],
            new DiagnosticTarget((string) $row['target_domain'], (string) $row['target_subject_type'], (string) $row['target_subject_id']),
            DiagnosticSessionStatus::from((string) $row['status']),
            $this->date($row['started_at'] ?? null),
            $this->date($row['completed_at'] ?? null),
            (int) $row['lock_version'],
            $this->evidence($organizationId, $sessionId),
            $this->records($organizationId, $sessionId),
        );
    }

    private function insertEvidence(string $organizationId, DiagnosticSession $session): void
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO diagnostic_evidence '
            . '(organization_id, session_id, evidence_id, evidence_type, title, source_reference, captured_at, metadata_json) '
            . 'VALUES (:organization_id, :session_id, :evidence_id, :evidence_type, :title, :source_reference, :captured_at, CAST(:metadata_json AS JSON))'
        );
        foreach ($session->evidence() as $evidence) {
            $statement->execute([
                'organization_id' => $organizationId, 'session_id' => $session->id(),
                'evidence_id' => $evidence->id, 'evidence_type' => $evidence->type->value,
                'title' => $evidence->title, 'source_reference' => $evidence->source,
                'captured_at' => $this->format($evidence->capturedAt),
                'metadata_json' => $this->json($evidence->metadata),
            ]);
        }
    }

    private function insertRecords(string $organizationId, DiagnosticSession $session): void
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO diagnostic_records '
            . '(organization_id, session_id, record_id, record_type, reference_code, statement, value_json, unit, '
            . 'evidence_ids_json, upstream_record_ids_json, recorded_at) '
            . 'VALUES (:organization_id, :session_id, :record_id, :record_type, :reference_code, :statement, '
            . 'CAST(:value_json AS JSON), :unit, CAST(:evidence_ids_json AS JSON), CAST(:upstream_record_ids_json AS JSON), :recorded_at)'
        );
        foreach ($session->records() as $record) {
            $statement->execute([
                'organization_id' => $organizationId, 'session_id' => $session->id(),
                'record_id' => $record->id, 'record_type' => $record->type->value,
                'reference_code' => $record->criterionCode, 'statement' => $record->statement,
                'value_json' => $this->json($record->value), 'unit' => $record->unit,
                'evidence_ids_json' => $this->json($record->evidenceIds),
                'upstream_record_ids_json' => $this->json($record->upstreamRecordIds),
                'recorded_at' => $this->format($record->recordedAt),
            ]);
        }
    }

    /** @return list<Evidence> */
    private function evidence(string $organizationId, string $sessionId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM diagnostic_evidence WHERE organization_id = :organization_id AND session_id = :session_id ORDER BY captured_at, evidence_id'
        );
        $statement->execute(['organization_id' => $organizationId, 'session_id' => $sessionId]);
        return array_map(fn (array $row): Evidence => new Evidence(
            (string) $row['evidence_id'], EvidenceType::from((string) $row['evidence_type']),
            (string) $row['title'], (string) $row['source_reference'], new DateTimeImmutable((string) $row['captured_at']),
            $this->decodeArray((string) $row['metadata_json']),
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<DiagnosticRecord> */
    private function records(string $organizationId, string $sessionId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM diagnostic_records WHERE organization_id = :organization_id AND session_id = :session_id ORDER BY recorded_at, record_id'
        );
        $statement->execute(['organization_id' => $organizationId, 'session_id' => $sessionId]);
        return array_map(fn (array $row): DiagnosticRecord => new DiagnosticRecord(
            (string) $row['record_id'], DiagnosticRecordType::from((string) $row['record_type']),
            (string) $row['reference_code'], (string) $row['statement'], $this->decode((string) $row['value_json']),
            $row['unit'] === null ? null : (string) $row['unit'],
            $this->decodeArray((string) $row['evidence_ids_json']),
            $this->decodeArray((string) $row['upstream_record_ids_json']),
            new DateTimeImmutable((string) $row['recorded_at']),
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function format(?DateTimeImmutable $value): ?string { return $value?->format('Y-m-d H:i:s.u'); }
    private function date(mixed $value): ?DateTimeImmutable { return $value === null || $value === '' ? null : new DateTimeImmutable((string) $value); }
    private function json(mixed $value): string
    {
        try { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        catch (JsonException $exception) { throw new RuntimeException('Unable to serialize diagnostic state.', 0, $exception); }
    }
    private function decode(string $value): mixed
    {
        try { return json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new RuntimeException('Unable to deserialize diagnostic state.', 0, $exception); }
    }
    private function decodeArray(string $value): array
    {
        $decoded = $this->decode($value);
        if (!is_array($decoded)) throw new RuntimeException('Diagnostic JSON collection must be an array.');
        return $decoded;
    }
}
