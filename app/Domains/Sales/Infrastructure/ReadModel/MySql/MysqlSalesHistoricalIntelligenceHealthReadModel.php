<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use Domains\Sales\Application\Contract\SalesHistoricalIntelligenceHealthReadModelInterface;
use PDO;

final readonly class MysqlSalesHistoricalIntelligenceHealthReadModel implements SalesHistoricalIntelligenceHealthReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function snapshot(string $organizationId): array
    {
        return [
            'stage' => [
                'quality' => $this->qualityCounts('sales_deal_stage_history', $organizationId),
                'current_deals' => $this->scalar(
                    'SELECT COUNT(*) FROM tn_client_cases WHERE organization_id=:org AND stage_id IS NOT NULL',
                    $organizationId,
                ),
                'missing_or_mismatched_open_projection' => $this->scalar(
                    'SELECT COUNT(*) FROM tn_client_cases c WHERE c.organization_id=:org AND c.stage_id IS NOT NULL '
                    . 'AND NOT EXISTS (SELECT 1 FROM sales_deal_stage_history h WHERE h.organization_id=c.organization_id '
                    . 'AND h.deal_id=CAST(c.id AS CHAR) AND h.left_at IS NULL AND h.to_stage_id=c.stage_id)',
                    $organizationId,
                ),
                'dangling_open_projection' => $this->scalar(
                    'SELECT COUNT(*) FROM sales_deal_stage_history h LEFT JOIN tn_client_cases c '
                    . 'ON c.organization_id=h.organization_id AND CAST(c.id AS CHAR)=h.deal_id '
                    . 'WHERE h.organization_id=:org AND h.left_at IS NULL AND (c.id IS NULL OR c.stage_id IS NULL)',
                    $organizationId,
                ),
                'duplicate_open_projection' => $this->scalar(
                    'SELECT COUNT(*) FROM (SELECT deal_id FROM sales_deal_stage_history WHERE organization_id=:org '
                    . 'AND left_at IS NULL GROUP BY deal_id HAVING COUNT(*)>1) d',
                    $organizationId,
                ),
            ],
            'owner' => [
                'quality' => $this->qualityCounts('sales_deal_owner_history', $organizationId),
                'assigned_deals' => $this->scalar(
                    'SELECT COUNT(*) FROM tn_client_cases WHERE organization_id=:org AND assigned_user_id IS NOT NULL',
                    $organizationId,
                ),
                'missing_or_mismatched_open_projection' => $this->scalar(
                    'SELECT COUNT(*) FROM tn_client_cases c WHERE c.organization_id=:org AND c.assigned_user_id IS NOT NULL '
                    . 'AND NOT EXISTS (SELECT 1 FROM sales_deal_owner_history h WHERE h.organization_id=c.organization_id '
                    . 'AND h.deal_id=CAST(c.id AS CHAR) AND h.unassigned_at IS NULL AND h.owner_user_id=c.assigned_user_id)',
                    $organizationId,
                ),
                'dangling_open_projection' => $this->scalar(
                    'SELECT COUNT(*) FROM sales_deal_owner_history h LEFT JOIN tn_client_cases c '
                    . 'ON c.organization_id=h.organization_id AND CAST(c.id AS CHAR)=h.deal_id '
                    . 'WHERE h.organization_id=:org AND h.unassigned_at IS NULL AND (c.id IS NULL OR c.assigned_user_id IS NULL)',
                    $organizationId,
                ),
                'duplicate_open_projection' => $this->scalar(
                    'SELECT COUNT(*) FROM (SELECT deal_id FROM sales_deal_owner_history WHERE organization_id=:org '
                    . 'AND unassigned_at IS NULL GROUP BY deal_id HAVING COUNT(*)>1) d',
                    $organizationId,
                ),
            ],
        ];
    }

    /** @return array<string,int> */
    private function qualityCounts(string $table, string $organizationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT history_quality,COUNT(*) total FROM ' . $table . ' WHERE organization_id=:org GROUP BY history_quality'
        );
        $statement->execute(['org' => $organizationId]);
        $result = ['COMPLETE' => 0, 'PARTIAL' => 0, 'ESTIMATED' => 0];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $quality = strtoupper((string) ($row['history_quality'] ?? ''));
            if (array_key_exists($quality, $result)) {
                $result[$quality] = (int) $row['total'];
            }
        }
        return $result;
    }

    private function scalar(string $sql, string $organizationId): int
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute(['org' => $organizationId]);
        return (int) $statement->fetchColumn();
    }
}
