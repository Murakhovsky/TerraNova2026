<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesOperationalPerformanceReadModelInterface;
use PDO;

final readonly class MysqlSalesOperationalPerformanceReadModel implements SalesOperationalPerformanceReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function communicationTimeline(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $pipelineId = null,
    ): array {
        $periodSql = $this->communicationSelect('c')
            . ' FROM sales_communications c '
            . 'INNER JOIN tn_client_cases d ON d.id=c.deal_id AND d.organization_id=c.organization_id '
            . 'WHERE c.organization_id=:organization_id AND c.occurred_at>=:from_at AND c.occurred_at<:to_at';
        $periodParams = [
            'organization_id' => $organizationId,
            'from_at' => $from->format('Y-m-d H:i:s.u'),
            'to_at' => $to->format('Y-m-d H:i:s.u'),
        ];
        if ($pipelineId !== null && trim($pipelineId) !== '') {
            $periodSql .= ' AND d.pipeline_id=:pipeline_id';
            $periodParams['pipeline_id'] = $pipelineId;
        }
        $period = $this->rows($periodSql, $periodParams);
        foreach ($period as &$row) $row['pre_period'] = false;
        unset($row);

        $previousSql = $this->communicationSelect('c')
            . ' FROM sales_communications c '
            . 'INNER JOIN tn_client_cases d ON d.id=c.deal_id AND d.organization_id=c.organization_id '
            . 'WHERE c.organization_id=:organization_id AND c.occurred_at<:from_at '
            . 'AND NOT EXISTS (SELECT 1 FROM sales_communications later '
            . 'WHERE later.organization_id=c.organization_id AND later.deal_id=c.deal_id AND later.channel=c.channel '
            . 'AND later.occurred_at<:from_later AND ('
            . 'later.occurred_at>c.occurred_at OR (later.occurred_at=c.occurred_at AND later.id>c.id)))';
        $previousParams = [
            'organization_id' => $organizationId,
            'from_at' => $from->format('Y-m-d H:i:s.u'),
            'from_later' => $from->format('Y-m-d H:i:s.u'),
        ];
        if ($pipelineId !== null && trim($pipelineId) !== '') {
            $previousSql .= ' AND d.pipeline_id=:pipeline_id';
            $previousParams['pipeline_id'] = $pipelineId;
        }
        $previous = $this->rows($previousSql, $previousParams);
        foreach ($previous as &$row) $row['pre_period'] = true;
        unset($row);

        $rows = [...$previous, ...$period];
        usort($rows, static function (array $left, array $right): int {
            return [
                (string) $left['deal_id'], (string) $left['channel'], (string) $left['occurred_at'], (string) $left['id'],
            ] <=> [
                (string) $right['deal_id'], (string) $right['channel'], (string) $right['occurred_at'], (string) $right['id'],
            ];
        });
        return $rows;
    }

    public function followupFacts(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $pipelineId = null,
    ): array {
        $sql = 'SELECT a.id AS activity_id,CAST(a.client_case_id AS CHAR) AS deal_id,a.due_at,a.completed_at,'
            . $this->ownerAtSql('a.organization_id', 'CAST(a.client_case_id AS CHAR)', 'a.due_at') . ' AS owner_id '
            . 'FROM tn_client_case_activities a '
            . 'INNER JOIN tn_client_cases d ON d.id=a.client_case_id AND d.organization_id=a.organization_id '
            . 'WHERE a.organization_id=:organization_id AND a.activity_type="followup" '
            . 'AND a.due_at>=:from_at AND a.due_at<:to_at';
        $params = [
            'organization_id' => $organizationId,
            'from_at' => $from->format('Y-m-d H:i:s.u'),
            'to_at' => $to->format('Y-m-d H:i:s.u'),
        ];
        if ($pipelineId !== null && trim($pipelineId) !== '') {
            $sql .= ' AND d.pipeline_id=:pipeline_id';
            $params['pipeline_id'] = $pipelineId;
        }
        $sql .= ' ORDER BY a.due_at,a.id';
        return $this->rows($sql, $params);
    }

    public function terminalOutcomeFacts(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $pipelineId = null,
    ): array {
        $historyOwner = $this->ownerAtSql('e.organization_id', 'e.aggregate_id', 'e.occurred_at');
        $sql = 'SELECT e.id,e.aggregate_id AS deal_id,e.type,e.occurred_at,'
            . 'NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload,"$.lost_reason_id")),"null") AS lost_reason_id,'
            . 'CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload,"$.deal_value")),"null") AS DECIMAL(20,4)) AS deal_value,'
            . 'UPPER(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload,"$.currency")),"null")) AS currency,'
            . 'CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload,"$.owner_id")),"null") AS UNSIGNED) AS snapshot_owner_id,'
            . $historyOwner . ' AS history_owner_id '
            . 'FROM cos_events e WHERE e.organization_id=:organization_id '
            . 'AND e.aggregate_type="deal" AND e.type IN ("sales.deal.won","sales.deal.lost") '
            . 'AND e.occurred_at>=:from_at AND e.occurred_at<:to_at';
        $params = [
            'organization_id' => $organizationId,
            'from_at' => $from->format('Y-m-d H:i:s.u'),
            'to_at' => $to->format('Y-m-d H:i:s.u'),
        ];
        if ($pipelineId !== null && trim($pipelineId) !== '') {
            $sql .= ' AND JSON_UNQUOTE(JSON_EXTRACT(e.payload,"$.pipeline_id"))=:pipeline_id';
            $params['pipeline_id'] = $pipelineId;
        }
        $sql .= ' ORDER BY e.occurred_at,e.id';

        $rows = $this->rows($sql, $params);
        foreach ($rows as &$row) {
            $snapshotOwner = (int) ($row['snapshot_owner_id'] ?? 0);
            $historyOwner = (int) ($row['history_owner_id'] ?? 0);
            $row['owner_id'] = $snapshotOwner > 0 ? $snapshotOwner : ($historyOwner > 0 ? $historyOwner : null);
            $row['owner_source'] = $snapshotOwner > 0 ? 'EVENT' : ($historyOwner > 0 ? 'HISTORY' : 'UNATTRIBUTED');
            $row['deal_value'] = $row['deal_value'] !== null ? (float) $row['deal_value'] : null;
        }
        unset($row);
        return $rows;
    }

    private function communicationSelect(string $alias): string
    {
        return 'SELECT ' . $alias . '.id,CAST(' . $alias . '.deal_id AS CHAR) AS deal_id,'
            . $alias . '.channel,' . $alias . '.direction,' . $alias . '.occurred_at,'
            . $this->ownerAtSql($alias . '.organization_id', 'CAST(' . $alias . '.deal_id AS CHAR)', $alias . '.occurred_at') . ' AS owner_id';
    }

    private function ownerAtSql(string $organizationExpression, string $dealExpression, string $timeExpression): string
    {
        return '(SELECT h.owner_user_id FROM sales_deal_owner_history h '
            . 'WHERE h.organization_id=' . $organizationExpression . ' AND h.deal_id=' . $dealExpression . ' '
            . 'AND h.history_quality IN ("COMPLETE","PARTIAL") AND h.assigned_at IS NOT NULL '
            . 'AND h.assigned_at<=' . $timeExpression . ' '
            . 'AND (h.unassigned_at IS NULL OR h.unassigned_at>' . $timeExpression . ') '
            . 'ORDER BY h.assigned_at DESC,h.id DESC LIMIT 1)';
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}
