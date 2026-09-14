<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesHistoricalEventStreamInterface;
use Domains\Sales\Automation\Event\DealCreated;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use PDO;

final readonly class MysqlSalesHistoricalEventStream implements SalesHistoricalEventStreamInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function forOrganization(string $organizationId): iterable
    {
        $statement = $this->connection->prepare(
            'SELECT id,organization_id,type,aggregate_type,aggregate_id,payload,schema_version,'
            . 'correlation_id,causation_id,actor_type,actor_id,occurred_at '
            . 'FROM cos_events WHERE organization_id=:organization_id '
            . 'AND type IN (:deal_created,:stage_changed,:owner_assigned) '
            . 'ORDER BY occurred_at ASC,id ASC'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'deal_created' => DealCreated::TYPE,
            'stage_changed' => DealStageChanged::TYPE,
            'owner_assigned' => SalesEventType::DEAL_OWNER_ASSIGNED,
        ]);

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $payload = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
            yield new DomainEvent(
                (string) $row['id'],
                (string) $row['organization_id'],
                (string) $row['type'],
                (string) ($row['aggregate_type'] ?? ''),
                (string) ($row['aggregate_id'] ?? ''),
                is_array($payload) ? $payload : [],
                new EventMetadata(
                    (string) $row['correlation_id'],
                    isset($row['causation_id']) ? (string) $row['causation_id'] : null,
                    (string) $row['actor_type'],
                    (string) $row['actor_id'],
                    (int) $row['schema_version'],
                ),
                new DateTimeImmutable((string) $row['occurred_at']),
            );
        }
    }
}
