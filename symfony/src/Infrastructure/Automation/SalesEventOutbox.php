<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Kernel\Event\Contract\EventOutboxInterface;
use Kernel\Event\OutboxMessage;
use PDO;
use Throwable;

final readonly class SalesEventOutbox implements EventOutboxInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function claim(string $workerId): ?OutboxMessage
    {
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                "SELECT o.* FROM cos_event_outbox o INNER JOIN cos_events e ON e.id=o.event_id "
                . "WHERE o.status IN ('PENDING','FAILED') AND o.available_at<=NOW(6) AND e.type LIKE 'sales.%' "
                . 'ORDER BY o.available_at,o.id LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->connection->commit();
                return null;
            }

            $update = $this->connection->prepare(
                "UPDATE cos_event_outbox SET status='PROCESSING',attempts=attempts+1,locked_at=NOW(6),locked_by=:worker "
                . "WHERE id=:id AND status IN ('PENDING','FAILED')"
            );
            $update->execute(['worker' => $workerId, 'id' => $row['id']]);
            if ($update->rowCount() !== 1) {
                $this->connection->rollBack();
                return null;
            }

            $this->connection->commit();

            return new OutboxMessage(
                (int) $row['id'],
                (string) $row['event_id'],
                (string) $row['organization_id'],
                (int) $row['attempts'] + 1,
                $workerId,
            );
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }

    public function markPublished(OutboxMessage $message): void
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_event_outbox SET status='PUBLISHED',published_at=NOW(6),locked_at=NULL,locked_by=NULL,last_error=NULL "
            . "WHERE id=:id AND status='PROCESSING' AND locked_by=:worker"
        );
        $statement->execute(['id' => $message->id, 'worker' => $message->claimedBy]);
    }

    public function markFailed(OutboxMessage $message, Throwable $error): void
    {
        $dead = $message->attempts >= 10;
        $delay = min(3600, 5 * (2 ** max(0, $message->attempts - 1)));
        $statement = $this->connection->prepare(
            'UPDATE cos_event_outbox SET status=:status,available_at=DATE_ADD(NOW(6),INTERVAL :delay SECOND),'
            . 'locked_at=NULL,locked_by=NULL,last_error=:error WHERE id=:id AND locked_by=:worker'
        );
        $statement->execute([
            'status' => $dead ? 'DEAD' : 'FAILED',
            'delay' => $dead ? 0 : $delay,
            'error' => mb_substr($error->getMessage(), 0, 65535),
            'id' => $message->id,
            'worker' => $message->claimedBy,
        ]);
    }

    public function recoverTimedOut(int $leaseSeconds = 300): int
    {
        $leaseSeconds = max(30, min($leaseSeconds, 86400));
        $statement = $this->connection->prepare(
            "UPDATE cos_event_outbox o INNER JOIN cos_events e ON e.id=o.event_id "
            . "SET o.status=CASE WHEN o.attempts>=10 THEN 'DEAD' ELSE 'FAILED' END,o.available_at=NOW(6),"
            . "o.locked_at=NULL,o.locked_by=NULL,o.last_error='Publisher lease timed out' "
            . "WHERE o.status='PROCESSING' AND o.locked_at<DATE_SUB(NOW(6),INTERVAL :lease SECOND) "
            . "AND e.type LIKE 'sales.%'"
        );
        $statement->execute(['lease' => $leaseSeconds]);

        return $statement->rowCount();
    }

    public function replay(?string $organizationId = null, ?string $eventId = null): int
    {
        $where = ["o.status IN ('PUBLISHED','FAILED','DEAD')", "e.type LIKE 'sales.%'"];
        $params = [];
        if ($organizationId !== null) {
            $where[] = 'o.organization_id=:organization_id';
            $params['organization_id'] = $organizationId;
        }
        if ($eventId !== null) {
            $where[] = 'o.event_id=:event_id';
            $params['event_id'] = $eventId;
        }

        $statement = $this->connection->prepare(
            "UPDATE cos_event_outbox o INNER JOIN cos_events e ON e.id=o.event_id "
            . "SET o.status='PENDING',o.attempts=0,o.available_at=NOW(6),o.published_at=NULL,o.locked_at=NULL,o.locked_by=NULL,o.last_error=NULL "
            . 'WHERE ' . implode(' AND ', $where)
        );
        $statement->execute($params);

        return $statement->rowCount();
    }
}
