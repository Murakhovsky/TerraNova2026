<?php

declare(strict_types=1);

namespace Infrastructure\Platform\ReadModel\MySql;

use DateTimeImmutable;
use JsonException;
use Kernel\Queue\AsyncOperationProjection;
use Kernel\Queue\AsyncOperationStatus;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;
use PDO;

final readonly class MysqlAsyncOperationReadModel implements AsyncOperationReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function recentForOrganization(string $organizationId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $statement = $this->connection->prepare(
            'SELECT j.id,j.organization_id,j.type,j.payload,j.status,j.attempts,j.max_attempts,'
            . 'j.available_at,j.locked_at,j.locked_by,j.completed_at,j.last_error,j.correlation_id,'
            . 'j.created_at,j.updated_at,a.target_type AS action_target_type,a.target_id AS action_target_id '
            . 'FROM cos_jobs j '
            . "LEFT JOIN cos_actions a ON j.type='ACTION_EXECUTION' "
            . 'AND a.organization_id=j.organization_id '
            . "AND a.id=JSON_UNQUOTE(JSON_EXTRACT(j.payload,'$.action_id')) "
            . 'WHERE j.organization_id=:organization_id '
            . 'ORDER BY j.created_at DESC LIMIT ' . $limit
        );
        $statement->execute(['organization_id' => $organizationId]);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->hydrate($row);
        }

        return $items;
    }

    public function find(string $organizationId, string $operationId): ?AsyncOperationProjection
    {
        $statement = $this->connection->prepare(
            'SELECT j.id,j.organization_id,j.type,j.payload,j.status,j.attempts,j.max_attempts,'
            . 'j.available_at,j.locked_at,j.locked_by,j.completed_at,j.last_error,j.correlation_id,'
            . 'j.created_at,j.updated_at,a.target_type AS action_target_type,a.target_id AS action_target_id '
            . 'FROM cos_jobs j '
            . "LEFT JOIN cos_actions a ON j.type='ACTION_EXECUTION' "
            . 'AND a.organization_id=j.organization_id '
            . "AND a.id=JSON_UNQUOTE(JSON_EXTRACT(j.payload,'$.action_id')) "
            . 'WHERE j.organization_id=:organization_id AND j.id=:id LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'id' => $operationId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): AsyncOperationProjection
    {
        $rawStatus = strtoupper((string) ($row['status'] ?? 'PENDING'));
        $status = match ($rawStatus) {
            'RUNNING' => AsyncOperationStatus::Running,
            'COMPLETED' => AsyncOperationStatus::Completed,
            'FAILED', 'DEAD' => AsyncOperationStatus::Failed,
            'CANCELLED' => AsyncOperationStatus::Cancelled,
            default => AsyncOperationStatus::Queued,
        };

        $payload = $this->payload((string) ($row['payload'] ?? '{}'));

        $entityType = $this->nullableString($row['action_target_type'] ?? null)
            ?? $this->payloadString($payload, ['entity_type', 'subject_type', 'target_type']);
        $entityId = $this->nullableString($row['action_target_id'] ?? null)
            ?? $this->payloadString($payload, ['entity_id', 'subject_id', 'target_id']);
        if (($entityType === null) !== ($entityId === null)) {
            $entityType = null;
            $entityId = null;
        }

        $progress = null;
        if (isset($payload['progress']) && is_numeric($payload['progress'])) {
            $progress = max(0, min(100, (int) round((float) $payload['progress'])));
        }

        $updatedAt = new DateTimeImmutable((string) $row['updated_at']);
        $completedAt = $this->date($row['completed_at'] ?? null);
        $finishedAt = $completedAt;
        if ($finishedAt === null && in_array($rawStatus, ['DEAD', 'CANCELLED'], true)) {
            $finishedAt = $updatedAt;
        }

        return new AsyncOperationProjection(
            id: (string) $row['id'],
            organizationId: (string) $row['organization_id'],
            type: (string) $row['type'],
            status: $status,
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            correlationId: (string) $row['correlation_id'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: $updatedAt,
            availableAt: $this->date($row['available_at'] ?? null),
            startedAt: $this->date($row['locked_at'] ?? null),
            finishedAt: $finishedAt,
            progress: $progress,
            actorId: $this->payloadString($payload, ['actor_id', 'user_id', 'requested_by']),
            entityType: $entityType,
            entityId: $entityId,
            workerId: $this->nullableString($row['locked_by'] ?? null),
            result: $this->payloadString($payload, ['result_label', 'result']),
            error: $this->nullableString($row['last_error'] ?? null),
            retryScheduled: $rawStatus === 'FAILED',
            canRetry: $rawStatus === 'DEAD',
        );
    }

    /** @return array<string,mixed> */
    private function payload(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $payload @param list<string> $keys */
    private function payloadString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->nullableString($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        $value = $this->nullableString($value);

        return $value === null ? null : new DateTimeImmutable($value);
    }
}
