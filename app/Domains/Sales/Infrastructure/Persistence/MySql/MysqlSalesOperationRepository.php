<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesOperationRepositoryInterface;
use PDO;
use RuntimeException;

final readonly class MysqlSalesOperationRepository implements SalesOperationRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function recordOutboundCommunication(
        string $organizationId,
        string $dealId,
        string $channel,
        string $body,
        string $externalId,
        string $idempotencyKey,
        array $metadata = [],
    ): ?string {
        if ($this->receipt($organizationId, 'message', $idempotencyKey) !== null) return null;
        $deal = $this->deal($organizationId, $dealId);
        if ($deal === null) throw new RuntimeException('Deal was not found in the current organization.');

        $id = bin2hex(random_bytes(16));
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO sales_communications '
            . '(id,organization_id,deal_id,person_id,channel,direction,sender,recipient,body,external_id,metadata,occurred_at) '
            . 'VALUES(:id,:org,:deal,:person,:channel,"OUTBOUND",:sender,:recipient,:body,:external,:metadata,NOW())'
        );
        $statement->execute([
            'id' => $id,
            'org' => $organizationId,
            'deal' => $dealId,
            'person' => $deal['person_id'],
            'channel' => $this->channel($channel),
            'sender' => 'COS',
            'recipient' => (string) $deal['person_id'],
            'body' => $body,
            'external' => $externalId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
        if ($statement->rowCount() !== 1) return null;

        $this->saveReceipt($organizationId, 'message', $idempotencyKey, $id);
        $this->touch($organizationId, $dealId);
        return $id;
    }

    public function recordInboundCommunication(
        string $organizationId,
        string $dealId,
        string $channel,
        string $sender,
        string $recipient,
        string $body,
        string $externalId,
        array $metadata = [],
    ): ?string {
        $deal = $this->deal($organizationId, $dealId);
        if ($deal === null) throw new RuntimeException('Deal was not found in the current organization.');

        $id = bin2hex(random_bytes(16));
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO sales_communications '
            . '(id,organization_id,deal_id,person_id,channel,direction,sender,recipient,body,external_id,metadata,occurred_at) '
            . 'VALUES(:id,:org,:deal,:person,:channel,"INBOUND",:sender,:recipient,:body,:external,:metadata,NOW())'
        );
        $statement->execute([
            'id' => $id,
            'org' => $organizationId,
            'deal' => $dealId,
            'person' => $deal['person_id'],
            'channel' => $this->channel($channel),
            'sender' => $sender,
            'recipient' => $recipient,
            'body' => $body,
            'external' => $externalId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
        if ($statement->rowCount() !== 1) return null;

        $this->touch($organizationId, $dealId);
        return $id;
    }

    public function scheduleMeeting(
        string $organizationId,
        string $dealId,
        string $title,
        DateTimeImmutable $scheduledAt,
        string $idempotencyKey,
        array $metadata = [],
    ): ?string {
        if ($this->receipt($organizationId, 'meeting', $idempotencyKey) !== null) return null;
        $deal = $this->deal($organizationId, $dealId);
        if ($deal === null) throw new RuntimeException('Deal was not found in the current organization.');

        $statement = $this->connection->prepare(
            'INSERT INTO tn_client_case_activities '
            . '(organization_id,client_case_id,person_id,user_id,activity_type,title,body,due_at,completed_at) '
            . 'VALUES(:org,:deal,:person,:owner,"meeting",:title,:body,:due,NULL)'
        );
        $statement->execute([
            'org' => $organizationId,
            'deal' => $dealId,
            'person' => $deal['person_id'],
            'owner' => $deal['assigned_user_id'],
            'title' => $title,
            'body' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'due' => $scheduledAt->format('Y-m-d H:i:s'),
        ]);
        $id = (string) $this->connection->lastInsertId();
        $this->saveReceipt($organizationId, 'meeting', $idempotencyKey, $id);
        return $id;
    }

    public function completeActivity(string $organizationId, string $dealId, int $activityId, ?int $userId): ?array
    {
        // Tenant + deal scope is part of the mutation predicate, so a guessed activity id cannot cross boundaries.
        $statement = $this->connection->prepare(
            'SELECT activity_type,title FROM tn_client_case_activities '
            . 'WHERE id=:activity AND organization_id=:org AND client_case_id=:deal AND completed_at IS NULL LIMIT 1'
        );
        $statement->execute(['activity' => $activityId, 'org' => $organizationId, 'deal' => $dealId]);
        $activity = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($activity)) return null;

        $update = $this->connection->prepare(
            'UPDATE tn_client_case_activities SET completed_at=NOW(), user_id=COALESCE(user_id,:user_id) '
            . 'WHERE id=:activity AND organization_id=:org AND client_case_id=:deal AND completed_at IS NULL'
        );
        $update->execute(['user_id' => $userId, 'activity' => $activityId, 'org' => $organizationId, 'deal' => $dealId]);
        if ($update->rowCount() !== 1) return null;

        $this->touch($organizationId, $dealId);
        return ['activity_type' => (string) $activity['activity_type'], 'title' => (string) $activity['title']];
    }

    public function rescheduleActivity(string $organizationId, string $dealId, int $activityId, DateTimeImmutable $dueAt): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE tn_client_case_activities SET due_at=:due '
            . 'WHERE id=:activity AND organization_id=:org AND client_case_id=:deal AND completed_at IS NULL'
        );
        $statement->execute([
            'due' => $dueAt->format('Y-m-d H:i:s'),
            'activity' => $activityId,
            'org' => $organizationId,
            'deal' => $dealId,
        ]);
        if ($statement->rowCount() !== 1) return false;

        $next = $this->connection->prepare(
            'UPDATE tn_client_cases SET next_contact_at=:due WHERE id=:deal AND organization_id=:org'
        );
        $next->execute(['due' => $dueAt->format('Y-m-d H:i:s'), 'deal' => $dealId, 'org' => $organizationId]);
        return true;
    }

    private function deal(string $organizationId, string $dealId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT person_id,assigned_user_id FROM tn_client_cases WHERE id=:id AND organization_id=:org'
        );
        $statement->execute(['id' => $dealId, 'org' => $organizationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function receipt(string $organizationId, string $type, string $key): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT mutation_id FROM sales_operation_receipts '
            . 'WHERE organization_id=:org AND operation_type=:type AND idempotency_key=:key'
        );
        $statement->execute(['org' => $organizationId, 'type' => $type, 'key' => $key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function saveReceipt(string $organizationId, string $type, string $key, string $id): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO sales_operation_receipts(organization_id,operation_type,idempotency_key,mutation_id) '
            . 'VALUES(:org,:type,:key,:id)'
        );
        $statement->execute(['org' => $organizationId, 'type' => $type, 'key' => $key, 'id' => $id]);
    }

    private function touch(string $organizationId, string $dealId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE tn_client_cases SET last_activity_at=NOW() WHERE id=:id AND organization_id=:org'
        );
        $statement->execute(['org' => $organizationId, 'id' => $dealId]);
    }

    private function channel(string $channel): string
    {
        $channel = strtoupper(trim($channel));
        return in_array($channel, ['TELEGRAM', 'EMAIL', 'PHONE', 'WEB', 'WHATSAPP', 'VIBER'], true) ? $channel : 'WEB';
    }
}
