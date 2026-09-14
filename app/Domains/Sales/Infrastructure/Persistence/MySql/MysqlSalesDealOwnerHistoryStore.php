<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesDealOwnerHistoryStoreInterface;
use Domains\Sales\Application\DTO\SalesDealOwnerHistoryEntry;
use Domains\Sales\Model\SalesHistoryQuality;
use PDO;
use RuntimeException;

final readonly class MysqlSalesDealOwnerHistoryStore implements SalesDealOwnerHistoryStoreInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function hasSourceEvent(string $organizationId, string $eventId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sales_deal_owner_history '
            . 'WHERE organization_id=:organization_id AND source_event_id=:event_id LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'event_id' => $eventId]);
        return $statement->fetchColumn() !== false;
    }

    public function currentOwner(string $organizationId, string $dealId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id,owner_user_id,assigned_at,unassigned_at,source_event_id,history_quality '
            . 'FROM sales_deal_owner_history '
            . 'WHERE organization_id=:organization_id AND deal_id=:deal_id AND unassigned_at IS NULL '
            . 'ORDER BY projected_at DESC,id DESC LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'deal_id' => $dealId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function ownerAt(string $organizationId, string $dealId, DateTimeImmutable $at): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id,owner_user_id,assigned_at,unassigned_at,source_event_id,history_quality '
            . 'FROM sales_deal_owner_history '
            . 'WHERE organization_id=:organization_id AND deal_id=:deal_id AND assigned_at IS NOT NULL '
            . 'AND assigned_at<=:at AND (unassigned_at IS NULL OR unassigned_at>:at_end) '
            . 'AND history_quality IN (:complete,:partial) '
            . 'ORDER BY assigned_at DESC,id DESC LIMIT 1'
        );
        $formatted = $at->format('Y-m-d H:i:s.u');
        $statement->execute([
            'organization_id' => $organizationId,
            'deal_id' => $dealId,
            'at' => $formatted,
            'at_end' => $formatted,
            'complete' => SalesHistoryQuality::Complete->value,
            'partial' => SalesHistoryQuality::Partial->value,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function append(SalesDealOwnerHistoryEntry $entry): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO sales_deal_owner_history '
            . '(id,organization_id,deal_id,owner_user_id,assigned_at,unassigned_at,source_event_id,correlation_id,history_quality) '
            . 'VALUES (:id,:organization_id,:deal_id,:owner_user_id,:assigned_at,NULL,:source_event_id,:correlation_id,:history_quality)'
        );
        $statement->execute([
            'id' => $entry->id,
            'organization_id' => $entry->organizationId,
            'deal_id' => $entry->dealId,
            'owner_user_id' => $entry->ownerUserId,
            'assigned_at' => $entry->assignedAt?->format('Y-m-d H:i:s.u'),
            'source_event_id' => $entry->sourceEventId,
            'correlation_id' => $entry->correlationId,
            'history_quality' => $entry->historyQuality->value,
        ]);
    }

    public function closeCurrentOwner(
        string $organizationId,
        string $historyId,
        DateTimeImmutable $unassignedAt,
        SalesHistoryQuality $quality,
    ): void {
        $statement = $this->connection->prepare(
            'UPDATE sales_deal_owner_history SET unassigned_at=:unassigned_at,history_quality=:history_quality '
            . 'WHERE id=:id AND organization_id=:organization_id AND unassigned_at IS NULL'
        );
        $statement->execute([
            'unassigned_at' => $unassignedAt->format('Y-m-d H:i:s.u'),
            'history_quality' => $quality->value,
            'id' => $historyId,
            'organization_id' => $organizationId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Open Sales owner history row could not be closed.');
        }
    }

    public function removeEstimatedCurrent(string $organizationId, string $dealId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM sales_deal_owner_history '
            . 'WHERE organization_id=:organization_id AND deal_id=:deal_id '
            . 'AND unassigned_at IS NULL AND history_quality=:history_quality'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'deal_id' => $dealId,
            'history_quality' => SalesHistoryQuality::Estimated->value,
        ]);
    }

    public function removeUnprovenCurrent(string $organizationId, string $dealId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM sales_deal_owner_history '
            . 'WHERE organization_id=:organization_id AND deal_id=:deal_id AND unassigned_at IS NULL '
            . 'AND source_event_id IS NULL AND history_quality<>:complete'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'deal_id' => $dealId,
            'complete' => SalesHistoryQuality::Complete->value,
        ]);
    }

    public function clearOrganization(string $organizationId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM sales_deal_owner_history WHERE organization_id=:organization_id'
        );
        $statement->execute(['organization_id' => $organizationId]);
    }

    public function backfillCurrentState(string $organizationId): int
    {
        $statement = $this->connection->prepare(
            "INSERT INTO sales_deal_owner_history "
            . "(id,organization_id,deal_id,owner_user_id,assigned_at,unassigned_at,source_event_id,correlation_id,history_quality) "
            . "SELECT CONCAT('estimated-owner:',LEFT(SHA2(CONCAT(c.organization_id,':',c.id,':',c.assigned_user_id),256),64)),"
            . "c.organization_id,CAST(c.id AS CHAR),c.assigned_user_id,NULL,NULL,NULL,NULL,'ESTIMATED' "
            . "FROM tn_client_cases c "
            . "WHERE c.organization_id=:organization_id AND c.assigned_user_id IS NOT NULL "
            . "AND NOT EXISTS (SELECT 1 FROM sales_deal_owner_history h "
            . "WHERE h.organization_id=c.organization_id AND h.deal_id=CAST(c.id AS CHAR) AND h.unassigned_at IS NULL)"
        );
        $statement->execute(['organization_id' => $organizationId]);
        return $statement->rowCount();
    }
}
