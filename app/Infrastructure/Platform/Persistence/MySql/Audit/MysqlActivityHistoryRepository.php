<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Audit;

use DateTimeImmutable;
use Kernel\Shared\Domain\OrganizationId;
use PDO;
use Platform\Audit\Contract\ActivityHistoryRepositoryInterface;
use Platform\Audit\Model\ActivityHistoryEntry;
use Platform\Audit\Model\ActivitySource;
use Platform\Audit\Model\Actor;
use Platform\Audit\Model\ResourceReference;

final readonly class MysqlActivityHistoryRepository implements ActivityHistoryRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function recent(OrganizationId $organizationId, int $limit = 100): array
    {
        return $this->query(
            'WHERE organization_id = :organization_id',
            ['organization_id' => $organizationId->value()],
            $limit,
        );
    }

    public function recentForResource(OrganizationId $organizationId, ResourceReference $resource, int $limit = 100): array
    {
        return $this->query(
            'WHERE organization_id = :organization_id AND subject_type = :subject_type AND subject_id = :subject_id',
            [
                'organization_id' => $organizationId->value(),
                'subject_type' => $resource->type,
                'subject_id' => $resource->id,
            ],
            $limit,
        );
    }

    public function byCorrelationId(OrganizationId $organizationId, string $correlationId, int $limit = 100): array
    {
        return $this->query(
            'WHERE organization_id = :organization_id AND correlation_id = :correlation_id',
            ['organization_id' => $organizationId->value(), 'correlation_id' => $correlationId],
            $limit,
        );
    }

    /** @param array<string,string> $parameters @return list<ActivityHistoryEntry> */
    private function query(string $where, array $parameters, int $limit): array
    {
        $limit = max(1, min(250, $limit));
        $statement = $this->connection->prepare(
            'SELECT id, organization_id, category, actor_type, actor_id, source_type, subject_type, subject_id, '
            . 'action, reason, input_references, changes, result, metadata, correlation_id, created_at '
            . 'FROM cos_audit_log ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $statement->execute($parameters);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->hydrate($row);
        }

        return $items;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ActivityHistoryEntry
    {
        $actorType = match ((string) $row['actor_type']) {
            'USER' => 'user',
            'AGENT' => 'agent',
            'WORKER' => 'worker',
            'INTEGRATION' => 'integration',
            default => 'system',
        };

        return new ActivityHistoryEntry(
            id: (string) $row['id'],
            organizationId: OrganizationId::fromString((string) $row['organization_id']),
            category: (string) $row['category'],
            actor: new Actor($actorType, (string) $row['actor_id']),
            source: ActivitySource::from((string) $row['source_type']),
            resource: new ResourceReference((string) $row['subject_type'], (string) $row['subject_id']),
            action: $row['action'] === null ? null : (string) $row['action'],
            reason: $row['reason'] === null ? null : (string) $row['reason'],
            input: $this->decode($row['input_references'] ?? null),
            changes: $this->decode($row['changes'] ?? null),
            result: $this->decode($row['result'] ?? null),
            correlationId: (string) $row['correlation_id'],
            timestamp: new DateTimeImmutable((string) $row['created_at']),
            metadata: $this->decode($row['metadata'] ?? null),
        );
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
