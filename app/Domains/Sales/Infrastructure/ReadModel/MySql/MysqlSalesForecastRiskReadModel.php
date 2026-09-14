<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesForecastRiskReadModelInterface;
use PDO;

final readonly class MysqlSalesForecastRiskReadModel implements SalesForecastRiskReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function openDeals(
        string $organizationId,
        DateTimeImmutable $asOf,
        ?string $pipelineId = null,
    ): array {
        $sql = 'SELECT CAST(c.id AS CHAR) deal_id,c.public_id,c.title,c.pipeline_id,c.stage_id,s.code stage_code,'
            . 'c.assigned_user_id current_owner_id,c.deal_value,UPPER(NULLIF(TRIM(c.currency),"")) currency,'
            . 'c.probability deal_probability,s.probability_default stage_probability,'
            . 'c.last_activity_at,c.next_contact_at,c.expected_close_at,'
            . 'h.entered_at,h.history_quality,t.stuck_after_seconds '
            . 'FROM tn_client_cases c '
            . 'INNER JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.organization_id=c.organization_id '
            . 'LEFT JOIN sales_stage_metric_thresholds t ON t.organization_id=c.organization_id AND t.stage_id=c.stage_id '
            . 'LEFT JOIN sales_deal_stage_history h ON h.id=(SELECT h2.id FROM sales_deal_stage_history h2 '
            . 'WHERE h2.organization_id=c.organization_id AND h2.deal_id=CAST(c.id AS CHAR) AND h2.left_at IS NULL '
            . 'ORDER BY COALESCE(h2.entered_at,h2.projected_at) DESC,h2.id DESC LIMIT 1) '
            . 'WHERE c.organization_id=:organization_id AND c.status IN ("active","paused") AND s.is_terminal=0';
        $params = ['organization_id' => $organizationId];
        if ($pipelineId !== null && trim($pipelineId) !== '') {
            $sql .= ' AND c.pipeline_id=:pipeline_id';
            $params['pipeline_id'] = trim($pipelineId);
        }
        $sql .= ' ORDER BY c.pipeline_id,s.sort_order,c.id';

        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['deal_id'] = (string) $row['deal_id'];
            $row['deal_value'] = $row['deal_value'] !== null ? (float) $row['deal_value'] : null;
            $row['deal_probability'] = $row['deal_probability'] !== null ? (float) $row['deal_probability'] : null;
            $row['stage_probability'] = $row['stage_probability'] !== null ? (float) $row['stage_probability'] : null;
            $row['stuck_after_seconds'] = $row['stuck_after_seconds'] !== null ? (int) $row['stuck_after_seconds'] : null;
            $row['current_owner_id'] = $row['current_owner_id'] !== null ? (int) $row['current_owner_id'] : null;
            if (($row['history_quality'] ?? null) === 'ESTIMATED') {
                $row['entered_at'] = null;
            }
        }
        unset($row);
        return $rows;
    }
}
