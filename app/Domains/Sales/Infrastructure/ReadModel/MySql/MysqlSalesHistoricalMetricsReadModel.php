<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesHistoricalMetricsReadModelInterface;
use Domains\Sales\Automation\Event\DealCreated;
use Domains\Sales\Automation\Event\SalesEventType;
use PDO;

final readonly class MysqlSalesHistoricalMetricsReadModel implements SalesHistoricalMetricsReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function pipelineMoney(string $organizationId, ?string $pipelineId = null): array
    {
        [$pipelineSql, $params] = $this->pipelineFilter($pipelineId, 'c');
        $statement = $this->connection->prepare(
            'SELECT UPPER(c.currency) currency,COUNT(*) deal_count,'
            . 'COALESCE(SUM(c.deal_value),0) pipeline_value,'
            . 'COALESCE(SUM(c.deal_value * COALESCE(c.probability,s.probability_default,0) / 100),0) weighted_pipeline '
            . 'FROM tn_client_cases c INNER JOIN sales_pipeline_stages s '
            . 'ON s.id=c.stage_id AND s.organization_id=c.organization_id '
            . 'WHERE c.organization_id=:organization_id AND s.is_terminal=0 '
            . 'AND c.status IN ("active","paused") AND c.deal_value IS NOT NULL '
            . 'AND TRIM(COALESCE(c.currency,""))<>""' . $pipelineSql
            . ' GROUP BY UPPER(c.currency) ORDER BY UPPER(c.currency)'
        );
        $statement->execute(['organization_id' => $organizationId, ...$params]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['deal_count'] = (int) $row['deal_count'];
            $row['pipeline_value'] = (float) $row['pipeline_value'];
            $row['weighted_pipeline'] = (float) $row['weighted_pipeline'];
        }
        unset($row);
        return $rows;
    }

    public function closedOutcomes(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        [$pipelineSql, $pipelineParams] = $this->eventPipelineFilter($pipelineId, 'e');
        $statement = $this->connection->prepare(
            'SELECT COUNT(DISTINCT CASE WHEN e.type=:won_type THEN e.aggregate_id END) won_count,'
            . 'COUNT(DISTINCT e.aggregate_id) closed_count FROM cos_events e '
            . 'WHERE e.organization_id=:organization_id AND e.aggregate_type="deal" '
            . 'AND e.type IN (:won_filter,:lost_filter) AND e.occurred_at>=:from_at AND e.occurred_at<:to_at'
            . $pipelineSql
        );
        $statement->execute([
            'won_type' => SalesEventType::DEAL_WON,
            'won_filter' => SalesEventType::DEAL_WON,
            'lost_filter' => SalesEventType::DEAL_LOST,
            'organization_id' => $organizationId,
            'from_at' => $this->date($from), 'to_at' => $this->date($to),
            ...$pipelineParams,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['won' => (int) ($row['won_count'] ?? 0), 'closed' => (int) ($row['closed_count'] ?? 0)];
    }

    public function createdCohortOutcomes(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        [$pipelineSql, $pipelineParams] = $this->eventPipelineFilter($pipelineId, 'created_event');
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) created_count,SUM(CASE WHEN won.won_at IS NOT NULL THEN 1 ELSE 0 END) won_count FROM ('
            . 'SELECT created_event.aggregate_id,MIN(created_event.occurred_at) created_at FROM cos_events created_event '
            . 'WHERE created_event.organization_id=:organization_id AND created_event.aggregate_type="deal" '
            . 'AND created_event.type=:created_type AND created_event.occurred_at>=:from_at AND created_event.occurred_at<:to_at'
            . $pipelineSql . ' GROUP BY created_event.aggregate_id) cohort '
            . 'LEFT JOIN (SELECT aggregate_id,MIN(occurred_at) won_at FROM cos_events '
            . 'WHERE organization_id=:won_organization_id AND aggregate_type="deal" AND type=:won_type AND occurred_at<:won_before '
            . 'GROUP BY aggregate_id) won ON won.aggregate_id=cohort.aggregate_id AND won.won_at>=cohort.created_at'
        );
        $statement->execute([
            'organization_id' => $organizationId, 'won_organization_id' => $organizationId,
            'created_type' => DealCreated::TYPE, 'won_type' => SalesEventType::DEAL_WON,
            'from_at' => $this->date($from), 'to_at' => $this->date($to), 'won_before' => $this->date($to),
            ...$pipelineParams,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['won' => (int) ($row['won_count'] ?? 0), 'created' => (int) ($row['created_count'] ?? 0)];
    }

    public function transitionFlow(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        [$pipelineSql, $params] = $this->pipelineFilter($pipelineId, 'h');
        $statement = $this->connection->prepare(
            'SELECT h.pipeline_id,h.from_stage_code,h.to_stage_code,COUNT(*) transition_count,'
            . 'SUM(h.history_quality="COMPLETE") complete_count,SUM(h.history_quality="PARTIAL") partial_count '
            . 'FROM sales_deal_stage_history h WHERE h.organization_id=:organization_id '
            . 'AND h.from_stage_code IS NOT NULL AND h.entered_at IS NOT NULL '
            . 'AND h.history_quality<>"ESTIMATED" AND h.entered_at>=:from_at AND h.entered_at<:to_at'
            . $pipelineSql
            . ' GROUP BY h.pipeline_id,h.from_stage_code,h.to_stage_code '
            . 'ORDER BY h.pipeline_id,h.from_stage_code,h.to_stage_code'
        );
        $statement->execute([
            'organization_id' => $organizationId, 'from_at' => $this->date($from), 'to_at' => $this->date($to), ...$params,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['transition_count'] = (int) $row['transition_count'];
            $row['history_quality'] = (int) $row['partial_count'] > 0 ? 'PARTIAL' : 'COMPLETE';
            unset($row['complete_count'], $row['partial_count']);
        }
        unset($row);
        return $rows;
    }

    public function cohortFunnel(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        [$eventPipelineSql, $eventPipelineParams] = $this->eventPipelineFilter($pipelineId, 'e');
        $createdStatement = $this->connection->prepare(
            'SELECT COUNT(DISTINCT e.aggregate_id) FROM cos_events e WHERE e.organization_id=:organization_id '
            . 'AND e.aggregate_type="deal" AND e.type=:created_type AND e.occurred_at>=:from_at AND e.occurred_at<:to_at'
            . $eventPipelineSql
        );
        $baseParams = [
            'organization_id' => $organizationId, 'created_type' => DealCreated::TYPE,
            'from_at' => $this->date($from), 'to_at' => $this->date($to), ...$eventPipelineParams,
        ];
        $createdStatement->execute($baseParams);
        $created = (int) $createdStatement->fetchColumn();

        $stageStatement = $this->connection->prepare(
            'SELECT h.pipeline_id,h.to_stage_code stage_code,COUNT(DISTINCT h.deal_id) reached_count,'
            . 'SUM(h.history_quality="PARTIAL") partial_rows '
            . 'FROM cos_events e INNER JOIN sales_deal_stage_history h '
            . 'ON h.organization_id=e.organization_id AND h.deal_id=e.aggregate_id '
            . 'WHERE e.organization_id=:organization_id AND e.aggregate_type="deal" AND e.type=:created_type '
            . 'AND e.occurred_at>=:from_at AND e.occurred_at<:to_at '
            . 'AND h.entered_at IS NOT NULL AND h.entered_at<:observed_before AND h.history_quality<>"ESTIMATED"'
            . $eventPipelineSql
            . ' GROUP BY h.pipeline_id,h.to_stage_code ORDER BY h.pipeline_id,MIN(h.entered_at),h.to_stage_code'
        );
        $stageStatement->execute([...$baseParams, 'observed_before' => $this->date($to)]);
        $stages = $stageStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($stages as &$row) {
            $row['reached_count'] = (int) $row['reached_count'];
            $row['history_quality'] = (int) $row['partial_rows'] > 0 ? 'PARTIAL' : 'COMPLETE';
            unset($row['partial_rows']);
        }
        unset($row);
        return ['created' => $created, 'stages' => $stages];
    }

    public function stageDurationSamples(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        [$pipelineSql, $params] = $this->pipelineFilter($pipelineId, 'h');
        $statement = $this->connection->prepare(
            'SELECT h.pipeline_id,h.to_stage_code stage_code,h.history_quality,h.duration_seconds '
            . 'FROM sales_deal_stage_history h WHERE h.organization_id=:organization_id '
            . 'AND h.left_at IS NOT NULL AND h.duration_seconds IS NOT NULL AND h.history_quality<>"ESTIMATED" '
            . 'AND h.left_at>=:from_at AND h.left_at<:to_at' . $pipelineSql
            . ' ORDER BY h.pipeline_id,h.to_stage_code,h.duration_seconds,h.id'
        );
        $statement->execute([
            'organization_id' => $organizationId, 'from_at' => $this->date($from), 'to_at' => $this->date($to), ...$params,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) $row['duration_seconds'] = (int) $row['duration_seconds'];
        unset($row);
        return $rows;
    }

    public function salesCycleSamples(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        [$eventPipelineSql, $pipelineParams] = $this->eventPipelineFilter($pipelineId, 'e');
        $statement = $this->connection->prepare(
            'SELECT created.pipeline_id,created.deal_id,won.won_at,'
            . 'TIMESTAMPDIFF(SECOND,created.created_at,won.won_at) cycle_seconds FROM ('
            . 'SELECT e.aggregate_id deal_id,JSON_UNQUOTE(JSON_EXTRACT(e.payload,"$.pipeline_id")) pipeline_id,MIN(e.occurred_at) created_at '
            . 'FROM cos_events e WHERE e.organization_id=:organization_id AND e.aggregate_type="deal" AND e.type=:created_type'
            . $eventPipelineSql . ' GROUP BY e.aggregate_id,JSON_UNQUOTE(JSON_EXTRACT(e.payload,"$.pipeline_id"))) created '
            . 'INNER JOIN (SELECT aggregate_id deal_id,MIN(occurred_at) won_at FROM cos_events '
            . 'WHERE organization_id=:won_organization_id AND aggregate_type="deal" AND type=:won_type '
            . 'GROUP BY aggregate_id) won ON won.deal_id=created.deal_id '
            . 'WHERE won.won_at>=:from_at AND won.won_at<:to_at AND won.won_at>=created.created_at '
            . 'ORDER BY won.won_at,created.deal_id'
        );
        $statement->execute([
            'organization_id' => $organizationId, 'won_organization_id' => $organizationId,
            'created_type' => DealCreated::TYPE, 'won_type' => SalesEventType::DEAL_WON,
            'from_at' => $this->date($from), 'to_at' => $this->date($to), ...$pipelineParams,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) $row['cycle_seconds'] = (int) $row['cycle_seconds'];
        unset($row);
        return $rows;
    }

    public function openDealRiskFacts(string $organizationId, DateTimeImmutable $asOf, ?string $pipelineId = null): array
    {
        [$pipelineSql, $params] = $this->pipelineFilter($pipelineId, 'c');
        $statement = $this->connection->prepare(
            'SELECT c.id deal_id,c.pipeline_id,c.stage_id,s.code stage_code,c.deal_value,UPPER(c.currency) currency,'
            . 'c.last_activity_at,c.next_contact_at,c.expected_close_at,h.entered_at,h.history_quality,t.stuck_after_seconds '
            . 'FROM tn_client_cases c INNER JOIN sales_pipeline_stages s '
            . 'ON s.id=c.stage_id AND s.organization_id=c.organization_id '
            . 'LEFT JOIN sales_stage_metric_thresholds t ON t.organization_id=c.organization_id AND t.stage_id=c.stage_id '
            . 'LEFT JOIN sales_deal_stage_history h ON h.id=(SELECT h2.id FROM sales_deal_stage_history h2 '
            . 'WHERE h2.organization_id=c.organization_id AND h2.deal_id=CAST(c.id AS CHAR) AND h2.left_at IS NULL '
            . 'ORDER BY COALESCE(h2.entered_at,h2.projected_at) DESC,h2.id DESC LIMIT 1) '
            . 'WHERE c.organization_id=:organization_id AND c.status IN ("active","paused") AND s.is_terminal=0'
            . $pipelineSql . ' ORDER BY c.pipeline_id,s.sort_order,c.id'
        );
        $statement->execute(['organization_id' => $organizationId, ...$params]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['deal_id'] = (string) $row['deal_id'];
            $row['deal_value'] = $row['deal_value'] !== null ? (float) $row['deal_value'] : null;
            $row['stuck_after_seconds'] = $row['stuck_after_seconds'] !== null ? (int) $row['stuck_after_seconds'] : null;
            if (($row['history_quality'] ?? null) === 'ESTIMATED') $row['entered_at'] = null;
        }
        unset($row);
        return $rows;
    }

    /** @return array{0:string,1:array<string,string>} */
    private function pipelineFilter(?string $pipelineId, string $alias): array
    {
        if ($pipelineId === null || $pipelineId === '') return ['', []];
        return [' AND ' . $alias . '.pipeline_id=:pipeline_id', ['pipeline_id' => $pipelineId]];
    }

    /** @return array{0:string,1:array<string,string>} */
    private function eventPipelineFilter(?string $pipelineId, string $alias): array
    {
        if ($pipelineId === null || $pipelineId === '') return ['', []];
        return [
            ' AND JSON_UNQUOTE(JSON_EXTRACT(' . $alias . '.payload,"$.pipeline_id"))=:pipeline_id',
            ['pipeline_id' => $pipelineId],
        ];
    }

    private function date(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
