<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use Domains\Sales\Application\Contract\SalesDealStageHistoryReadModelInterface;
use Domains\Sales\Model\SalesHistoryQuality;
use PDO;

final readonly class MysqlSalesDealStageHistoryReadModel implements SalesDealStageHistoryReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function dealHistory(string $organizationId, string $dealId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id,organization_id,deal_id,pipeline_id,from_stage_id,from_stage_code,to_stage_id,to_stage_code,'
            . 'entered_at,left_at,duration_seconds,source_event_id,correlation_id,history_quality,projected_at '
            . 'FROM sales_deal_stage_history '
            . 'WHERE organization_id=:organization_id AND deal_id=:deal_id '
            . 'ORDER BY COALESCE(entered_at,projected_at) ASC,id ASC'
        );
        $statement->execute(['organization_id' => $organizationId, 'deal_id' => $dealId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['duration_seconds'] = $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null;
        }
        unset($row);
        return $rows;
    }

    public function qualitySummary(string $organizationId): array
    {
        $summary = [
            SalesHistoryQuality::Complete->value => 0,
            SalesHistoryQuality::Partial->value => 0,
            SalesHistoryQuality::Estimated->value => 0,
        ];
        $statement = $this->connection->prepare(
            'SELECT history_quality,COUNT(*) total FROM sales_deal_stage_history '
            . 'WHERE organization_id=:organization_id GROUP BY history_quality'
        );
        $statement->execute(['organization_id' => $organizationId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $quality = (string) ($row['history_quality'] ?? '');
            if (array_key_exists($quality, $summary)) {
                $summary[$quality] = (int) ($row['total'] ?? 0);
            }
        }
        return $summary;
    }
}
