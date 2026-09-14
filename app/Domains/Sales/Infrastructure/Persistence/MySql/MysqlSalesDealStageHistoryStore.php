<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesDealStageHistoryStoreInterface;
use Domains\Sales\Application\DTO\SalesDealStageHistoryEntry;
use Domains\Sales\Model\SalesHistoryQuality;
use PDO;
use RuntimeException;

final readonly class MysqlSalesDealStageHistoryStore implements SalesDealStageHistoryStoreInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function hasSourceEvent(string $organizationId, string $eventId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM sales_deal_stage_history '
            . 'WHERE organization_id=:organization_id AND source_event_id=:event_id LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'event_id' => $eventId]);
        return $statement->fetchColumn() !== false;
    }

    public function currentStage(string $organizationId, string $dealId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id,to_stage_id,to_stage_code,entered_at,history_quality '
            . 'FROM sales_deal_stage_history '
            . 'WHERE organization_id=:organization_id AND deal_id=:deal_id AND left_at IS NULL '
            . 'ORDER BY projected_at DESC,id DESC LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'deal_id' => $dealId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function append(SalesDealStageHistoryEntry $entry): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO sales_deal_stage_history '
            . '(id,organization_id,deal_id,pipeline_id,from_stage_id,from_stage_code,to_stage_id,to_stage_code,'
            . 'entered_at,left_at,duration_seconds,source_event_id,correlation_id,history_quality) '
            . 'VALUES (:id,:organization_id,:deal_id,:pipeline_id,:from_stage_id,:from_stage_code,:to_stage_id,:to_stage_code,'
            . ':entered_at,NULL,NULL,:source_event_id,:correlation_id,:history_quality)'
        );
        $statement->execute([
            'id' => $entry->id,
            'organization_id' => $entry->organizationId,
            'deal_id' => $entry->dealId,
            'pipeline_id' => $entry->pipelineId,
            'from_stage_id' => $entry->fromStageId,
            'from_stage_code' => $entry->fromStageCode,
            'to_stage_id' => $entry->toStageId,
            'to_stage_code' => $entry->toStageCode,
            'entered_at' => $entry->enteredAt?->format('Y-m-d H:i:s.u'),
            'source_event_id' => $entry->sourceEventId,
            'correlation_id' => $entry->correlationId,
            'history_quality' => $entry->historyQuality->value,
        ]);
    }

    public function closeCurrentStage(
        string $organizationId,
        string $historyId,
        DateTimeImmutable $leftAt,
        SalesHistoryQuality $quality,
    ): void {
        $statement = $this->connection->prepare(
            'UPDATE sales_deal_stage_history SET '
            . 'left_at=:left_at,'
            . 'duration_seconds=CASE WHEN entered_at IS NULL THEN NULL '
            . 'ELSE GREATEST(0,TIMESTAMPDIFF(SECOND,entered_at,:duration_left_at)) END,'
            . 'history_quality=:history_quality '
            . 'WHERE id=:id AND organization_id=:organization_id AND left_at IS NULL'
        );
        $formatted = $leftAt->format('Y-m-d H:i:s.u');
        $statement->execute([
            'left_at' => $formatted,
            'duration_left_at' => $formatted,
            'history_quality' => $quality->value,
            'id' => $historyId,
            'organization_id' => $organizationId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Open Sales stage history row could not be closed.');
        }
    }

    public function removeEstimatedCurrent(string $organizationId, string $dealId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM sales_deal_stage_history '
            . 'WHERE organization_id=:organization_id AND deal_id=:deal_id '
            . 'AND left_at IS NULL AND history_quality=:history_quality'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'deal_id' => $dealId,
            'history_quality' => SalesHistoryQuality::Estimated->value,
        ]);
    }

    public function clearOrganization(string $organizationId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM sales_deal_stage_history WHERE organization_id=:organization_id'
        );
        $statement->execute(['organization_id' => $organizationId]);
    }

    public function backfillCurrentState(string $organizationId): int
    {
        $statement = $this->connection->prepare(
            "INSERT INTO sales_deal_stage_history "
            . "(id,organization_id,deal_id,pipeline_id,from_stage_id,from_stage_code,to_stage_id,to_stage_code,"
            . "entered_at,left_at,duration_seconds,source_event_id,correlation_id,history_quality) "
            . "SELECT CONCAT('estimated:',LEFT(SHA2(CONCAT(c.organization_id,':',c.id,':',"
            . "COALESCE(c.pipeline_id,''),':',c.stage_id),256),64)),"
            . "c.organization_id,CAST(c.id AS CHAR),c.pipeline_id,NULL,NULL,c.stage_id,"
            . "COALESCE(s.code,UPPER(c.stage)),NULL,NULL,NULL,NULL,NULL,'ESTIMATED' "
            . "FROM tn_client_cases c "
            . "LEFT JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.organization_id=c.organization_id "
            . "WHERE c.organization_id=:organization_id AND c.stage_id IS NOT NULL "
            . "AND COALESCE(s.code,UPPER(c.stage),'')<>'' "
            . "AND NOT EXISTS (SELECT 1 FROM sales_deal_stage_history h "
            . "WHERE h.organization_id=c.organization_id AND h.deal_id=CAST(c.id AS CHAR) AND h.left_at IS NULL)"
        );
        $statement->execute(['organization_id' => $organizationId]);
        return $statement->rowCount();
    }
}
