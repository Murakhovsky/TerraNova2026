<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm;

use Domains\Sales\Application\Contract\CrmInboxRepositoryInterface;
use Domains\Sales\Application\DTO\CrmInboxItem;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MysqlCrmInboxRepository implements CrmInboxRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function receive(
        string $organizationId,
        string $provider,
        string $externalEventId,
        string $eventType,
        array $payload,
        string $correlationId,
    ): string {
        $id = bin2hex(random_bytes(16));
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO cos_crm_inbox '
            . '(id, organization_id, provider, external_event_id, event_type, payload, payload_hash, signature_verified, '
            . "status, available_at, correlation_id) VALUES (:id, :organization_id, :provider, :external_event_id, "
            . ":event_type, :payload, :payload_hash, 1, 'RECEIVED', NOW(6), :correlation_id)"
        );
        $statement->execute([
            'id' => $id,
            'organization_id' => $organizationId,
            'provider' => $provider,
            'external_event_id' => $externalEventId,
            'event_type' => $eventType,
            'payload' => $json,
            'payload_hash' => hash('sha256', $json),
            'correlation_id' => $correlationId,
        ]);
        if ($statement->rowCount() === 1) return $id;
        $existing = $this->connection->prepare(
            'SELECT id FROM cos_crm_inbox WHERE organization_id = :organization_id '
            . 'AND provider = :provider AND external_event_id = :external_event_id LIMIT 1'
        );
        $existing->execute([
            'organization_id' => $organizationId,
            'provider' => $provider,
            'external_event_id' => $externalEventId,
        ]);
        $existingId = $existing->fetchColumn();
        if ($existingId === false) throw new RuntimeException('Unable to persist CRM inbox item.');
        return (string) $existingId;
    }

    public function claim(string $organizationId, string $id, string $workerId): ?CrmInboxItem
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_crm_inbox SET status = 'PROCESSING', attempts = attempts + 1, locked_at = NOW(6), locked_by = :worker "
            . "WHERE id = :id AND organization_id = :organization_id AND status IN ('RECEIVED', 'FAILED') "
            . 'AND available_at <= NOW(6)'
        );
        $statement->execute(['worker' => $workerId, 'id' => $id, 'organization_id' => $organizationId]);
        if ($statement->rowCount() !== 1) return null;
        $read = $this->connection->prepare(
            'SELECT * FROM cos_crm_inbox WHERE id = :id AND organization_id = :organization_id LIMIT 1'
        );
        $read->execute(['id' => $id, 'organization_id' => $organizationId]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return new CrmInboxItem(
            (string) $row['id'],
            (string) $row['organization_id'],
            (string) $row['provider'],
            (string) $row['external_event_id'],
            (string) $row['event_type'],
            json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR),
            (int) $row['attempts'],
            (string) $row['correlation_id'],
        );
    }

    public function complete(CrmInboxItem $item): void
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_crm_inbox SET status = 'COMPLETED', processed_at = NOW(6), locked_at = NULL, "
            . 'locked_by = NULL, last_error = NULL WHERE id = :id AND organization_id = :organization_id'
        );
        $statement->execute(['id' => $item->id, 'organization_id' => $item->organizationId]);
    }

    public function fail(CrmInboxItem $item, Throwable $error): void
    {
        $dead = $item->attempts >= 10;
        $statement = $this->connection->prepare(
            'UPDATE cos_crm_inbox SET status = :status, available_at = NOW(6), '
            . 'locked_at = NULL, locked_by = NULL, last_error = :error WHERE id = :id AND organization_id = :organization_id'
        );
        $statement->execute([
            'status' => $dead ? 'DEAD' : 'FAILED',
            'error' => mb_substr($error->getMessage(), 0, 65535),
            'id' => $item->id,
            'organization_id' => $item->organizationId,
        ]);
    }
}
