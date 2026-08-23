<?php
declare(strict_types=1);

namespace Infrastructure\Database\Event;

use DateTimeImmutable;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use PDO;
use RuntimeException;

final readonly class MysqlEventStore implements EventStoreInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function append(DomainEvent $event): void
    {
        if (!$this->connection->inTransaction()) {
            throw new RuntimeException('Domain events must be appended inside the business transaction.');
        }

        $statement = $this->connection->prepare(
            'INSERT INTO cos_events '
            . '(id, organization_id, type, aggregate_type, aggregate_id, payload, metadata, schema_version, '
            . 'correlation_id, causation_id, actor_type, actor_id, occurred_at) '
            . 'VALUES (:id, :organization_id, :type, :aggregate_type, :aggregate_id, :payload, :metadata, '
            . ':schema_version, :correlation_id, :causation_id, :actor_type, :actor_id, :occurred_at)'
        );
        $statement->execute([
            'id' => $event->id,
            'organization_id' => $event->organizationId,
            'type' => $event->type,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'metadata' => json_encode($event->metadata, JSON_THROW_ON_ERROR),
            'schema_version' => $event->metadata->schemaVersion,
            'correlation_id' => $event->metadata->correlationId,
            'causation_id' => $event->metadata->causationId,
            'actor_type' => $event->metadata->actorType,
            'actor_id' => $event->metadata->actorId,
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s.u'),
        ]);

        $outbox = $this->connection->prepare(
            "INSERT INTO cos_event_outbox (event_id, organization_id, status, available_at) "
            . "VALUES (:event_id, :organization_id, 'PENDING', NOW(6))"
        );
        $outbox->execute(['event_id' => $event->id, 'organization_id' => $event->organizationId]);
    }

    public function find(string $eventId): ?DomainEvent
    {
        $statement = $this->connection->prepare('SELECT * FROM cos_events WHERE id = :id');
        $statement->execute(['id' => $eventId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByAggregate(
        string $organizationId,
        string $aggregateType,
        string $aggregateId,
        int $limit = 100,
    ): array {
        $limit = max(1, min($limit, 500));
        $statement = $this->connection->prepare(
            'SELECT * FROM cos_events '
            . 'WHERE organization_id = :organization_id AND aggregate_type = :aggregate_type '
            . 'AND aggregate_id = :aggregate_id ORDER BY occurred_at ASC LIMIT ' . $limit
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
        ]);

        return array_map($this->hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): DomainEvent
    {
        return new DomainEvent(
            (string) $row['id'],
            (string) $row['organization_id'],
            (string) $row['type'],
            (string) $row['aggregate_type'],
            (string) $row['aggregate_id'],
            $this->decode((string) $row['payload']),
            EventMetadata::fromArray($this->decode((string) $row['metadata'])),
            new DateTimeImmutable((string) $row['occurred_at']),
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : [];
    }
}
