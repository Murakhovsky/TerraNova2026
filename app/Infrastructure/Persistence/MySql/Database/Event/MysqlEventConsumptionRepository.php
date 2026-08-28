<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Database\Event;

use Kernel\Event\Contract\EventConsumptionRepositoryInterface;
use Kernel\Event\DomainEvent;
use PDO;
use Throwable;

final readonly class MysqlEventConsumptionRepository implements EventConsumptionRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function begin(DomainEvent $event, string $consumerName): bool
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_event_consumptions '
            . "(event_id, organization_id, consumer_name, status, attempts, started_at) VALUES "
            . "(:event_id, :organization_id, :consumer_name, 'PROCESSING', 1, NOW(6)) "
            . "ON DUPLICATE KEY UPDATE attempts = IF(status = 'COMPLETED', attempts, attempts + 1), "
            . "status = IF(status = 'COMPLETED', status, 'PROCESSING'), started_at = IF(status = 'COMPLETED', started_at, NOW(6)), "
            . "last_error = IF(status = 'COMPLETED', last_error, NULL)"
        );
        $statement->execute([
            'event_id' => $event->id,
            'organization_id' => $event->organizationId,
            'consumer_name' => $consumerName,
        ]);
        $check = $this->connection->prepare(
            'SELECT status FROM cos_event_consumptions WHERE event_id = :event_id AND consumer_name = :consumer_name'
        );
        $check->execute(['event_id' => $event->id, 'consumer_name' => $consumerName]);
        return $check->fetchColumn() !== 'COMPLETED';
    }

    public function complete(DomainEvent $event, string $consumerName): void
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_event_consumptions SET status = 'COMPLETED', processed_at = NOW(6), last_error = NULL "
            . 'WHERE event_id = :event_id AND consumer_name = :consumer_name'
        );
        $statement->execute(['event_id' => $event->id, 'consumer_name' => $consumerName]);
    }

    public function fail(DomainEvent $event, string $consumerName, Throwable $error): void
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_event_consumptions SET status = CASE WHEN attempts >= 10 THEN 'DEAD' ELSE 'FAILED' END, "
            . 'processed_at = NOW(6), last_error = :error WHERE event_id = :event_id AND consumer_name = :consumer_name'
        );
        $statement->execute([
            'event_id' => $event->id,
            'consumer_name' => $consumerName,
            'error' => mb_substr($error->getMessage(), 0, 65535),
        ]);
    }

    public function reset(?string $organizationId = null, ?string $eventId = null): int
    {
        $where = ['1 = 1'];
        $params = [];
        if ($organizationId !== null) {
            $where[] = 'organization_id = :organization_id';
            $params['organization_id'] = $organizationId;
        }
        if ($eventId !== null) {
            $where[] = 'event_id = :event_id';
            $params['event_id'] = $eventId;
        }
        $statement = $this->connection->prepare(
            'DELETE FROM cos_event_consumptions WHERE ' . implode(' AND ', $where)
        );
        $statement->execute($params);
        return $statement->rowCount();
    }
}
