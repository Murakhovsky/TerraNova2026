<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesAttentionRepositoryInterface;
use PDO;

final readonly class MysqlSalesAttentionRepository implements SalesAttentionRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function activeOrganizations(): array
    {
        $statement = $this->connection->query(
            'SELECT DISTINCT organization_id FROM sales_pipelines WHERE status="ACTIVE" ORDER BY organization_id'
        );

        return array_values(array_filter(array_map(
            'strval',
            $statement->fetchAll(PDO::FETCH_COLUMN) ?: [],
        )));
    }

    public function inactiveDeals(string $organizationId, DateTimeImmutable $cutoff, int $limit): array
    {
        $statement = $this->connection->prepare(
            'SELECT id,last_activity_at FROM tn_client_cases '
            . 'WHERE organization_id=:org AND status="active" '
            . 'AND COALESCE(last_activity_at,created_at)<=:cutoff '
            . 'ORDER BY COALESCE(last_activity_at,created_at) LIMIT ' . max(1, min(500, $limit))
        );
        $statement->execute(['org' => $organizationId, 'cutoff' => $cutoff->format('Y-m-d H:i:s')]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function missedFollowups(string $organizationId, DateTimeImmutable $now, int $limit): array
    {
        $statement = $this->connection->prepare(
            'SELECT id,client_case_id,due_at FROM tn_client_case_activities '
            . 'WHERE organization_id=:org AND activity_type="followup" '
            . 'AND completed_at IS NULL AND due_at<:now '
            . 'ORDER BY due_at LIMIT ' . max(1, min(500, $limit))
        );
        $statement->execute(['org' => $organizationId, 'now' => $now->format('Y-m-d H:i:s')]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function stuckDeals(string $organizationId, DateTimeImmutable $now, int $limit): array
    {
        $statement = $this->connection->prepare(
            'SELECT c.id,c.stage_id,c.last_activity_at,c.next_contact_at,'
            . 'h.entered_at,h.history_quality,t.stuck_after_seconds '
            . 'FROM tn_client_cases c '
            . 'INNER JOIN sales_stage_metric_thresholds t '
            . 'ON CONVERT(t.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
            . 'CONVERT(c.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci '
            . 'AND CONVERT(t.stage_id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
            . 'CONVERT(c.stage_id USING utf8mb4) COLLATE utf8mb4_unicode_ci '
            . 'INNER JOIN sales_deal_stage_history h ON h.id=('
            . 'SELECT h2.id FROM sales_deal_stage_history h2 '
            . 'WHERE CONVERT(h2.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
            . 'CONVERT(c.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci '
            . 'AND CONVERT(h2.deal_id USING utf8mb4) COLLATE utf8mb4_unicode_ci='
            . 'CONVERT(CAST(c.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci '
            . 'AND h2.left_at IS NULL '
            . 'ORDER BY COALESCE(h2.entered_at,h2.projected_at) DESC,h2.id DESC LIMIT 1'
            . ') '
            . 'WHERE c.organization_id=:org AND c.status="active" AND t.stuck_after_seconds>0 '
            . 'AND COALESCE(h.history_quality,"COMPLETE")<>"ESTIMATED" '
            . 'AND TIMESTAMPDIFF(SECOND,h.entered_at,:now_entered)>=t.stuck_after_seconds '
            . 'AND (c.last_activity_at IS NULL OR TIMESTAMPDIFF(SECOND,c.last_activity_at,:now_activity)>=t.stuck_after_seconds) '
            . 'AND (c.next_contact_at IS NULL OR c.next_contact_at<=:now_contact) '
            . 'ORDER BY h.entered_at LIMIT ' . max(1, min(500, $limit))
        );
        $formatted = $now->format('Y-m-d H:i:s');
        $statement->execute([
            'org' => $organizationId,
            'now_entered' => $formatted,
            'now_activity' => $formatted,
            'now_contact' => $formatted,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function claimSignal(string $organizationId, string $type, string $windowKey, string $mutationId): bool
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO sales_operation_receipts(organization_id,operation_type,idempotency_key,mutation_id) '
            . 'VALUES(:org,:type,:window_key,:mutation_id)'
        );
        $statement->execute([
            'org' => $organizationId,
            'type' => $type,
            'window_key' => $windowKey,
            'mutation_id' => $mutationId,
        ]);

        return $statement->rowCount() === 1;
    }
}
