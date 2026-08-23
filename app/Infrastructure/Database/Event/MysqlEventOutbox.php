<?php
declare(strict_types=1);

namespace Infrastructure\Database\Event;

use Kernel\Event\Contract\EventOutboxInterface;
use Kernel\Event\DomainEvent;
use PDO;
use RuntimeException;

final readonly class MysqlEventOutbox implements EventOutboxInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function append(DomainEvent $event): void
    {
        if (!$this->connection->inTransaction()) {
            throw new RuntimeException('Outbox events must be appended inside the business transaction.');
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
}
