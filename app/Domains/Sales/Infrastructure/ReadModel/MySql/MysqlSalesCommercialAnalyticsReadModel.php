<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

/**
 * Native commercial read model. No Finance balances, implicit FX or PII.
 * Caller must resolve live Sales authorization before passing the owner scope.
 */
final readonly class MysqlSalesCommercialAnalyticsReadModel
{
    public function __construct(private PDO $connection) {}

    /**
     * First recorded terminal outcome per deal, with event-time attribution.
     * Legacy deals without terminal events are not fabricated from current state.
     */
    public function outcomes(string $org, ?int $owner, ?string $pipeline, string $from, string $to): array
    {
        $this->validate($org, $owner, $pipeline, $from, $to);
        $params = ['org'=>$org,'from_at'=>$from,'to_at'=>$to];
        $filters = [];
        if ($owner !== null) {
            $filters[] = 'snapshot_owner = :owner';
            $params['owner'] = (string)$owner;
        }
        if ($pipeline !== null) {
            $filters[] = 'snapshot_pipeline = :pipeline';
            $params['pipeline'] = $pipeline;
        }
        $where = $filters === [] ? '' : ' AND '.implode(' AND ', $filters);
        $sql = <<<'SQL'
WITH first_terminal AS (
 SELECT e.type, e.occurred_at,
 NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.owner_id')),'null') snapshot_owner,
 NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.pipeline_id')),'null') snapshot_pipeline,
 UPPER(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.currency')),'null')) raw_currency,
 NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.deal_value')),'null') raw_value
 FROM cos_events e
 WHERE e.organization_id=:org AND e.aggregate_type='deal'
 AND e.type IN ('sales.deal.won','sales.deal.lost')
 AND e.occurred_at>=:from_at AND e.occurred_at<:to_at
 AND NOT EXISTS (
   SELECT 1 FROM cos_events prev
   WHERE prev.organization_id=e.organization_id AND prev.aggregate_type=e.aggregate_type
   AND prev.aggregate_id=e.aggregate_id AND prev.type IN ('sales.deal.won','sales.deal.lost')
   AND (prev.occurred_at<e.occurred_at OR (prev.occurred_at=e.occurred_at AND prev.id<e.id))
 )
), facts AS (
 SELECT type, snapshot_owner,
 CASE WHEN type='sales.deal.won' AND raw_currency IN ('USD','EUR','UAH')
 AND raw_value REGEXP '^[0-9]{1,18}([.][0-9]{1,2})?$'
 AND CAST(raw_value AS DECIMAL(20,2))>0 THEN raw_currency ELSE NULL END booked_currency,
 CASE WHEN raw_value REGEXP '^[0-9]{1,18}([.][0-9]{1,2})?$'
 THEN CAST(raw_value AS DECIMAL(20,2)) ELSE NULL END amount
 FROM first_terminal WHERE 1=1
SQL;
        $sql .= $where . <<<'SQL'

)
SELECT type, booked_currency, COUNT(*) deal_count,
 SUM(CASE WHEN snapshot_owner IS NULL OR snapshot_owner NOT REGEXP '^[1-9][0-9]*$' THEN 1 ELSE 0 END) unattributed_count,
 CASE WHEN booked_currency IS NOT NULL THEN CAST(SUM(amount) AS DECIMAL(38,2)) ELSE NULL END booked_value
FROM facts GROUP BY type, booked_currency ORDER BY type, booked_currency
SQL;
        $won=0; $lost=0; $known=0; $unattributed=0; $money=[];
        foreach ($this->rows($sql,$params) as $row) {
            $count=(int)$row['deal_count'];
            $unattributed+=(int)$row['unattributed_count'];
            if ($row['type']==='sales.deal.won') {
                $won+=$count;
                if ($row['booked_currency']!==null) {
                    $known+=$count;
                    $money[]=['currency'=>$row['booked_currency'],'deal_count'=>$count,'booked_value'=>(string)$row['booked_value']];
                }
            } else {
                $lost+=$count;
            }
        }
        return [
            'from'=>$from,'to_exclusive'=>$to,'owner_id'=>$owner,'pipeline_id'=>$pipeline,
            'basis'=>'first_recorded_terminal_event','won_count'=>$won,'lost_count'=>$lost,
            'win_rate_percent'=>$won+$lost===0?null:number_format(100*$won/($won+$lost),2,'.',''),
            'unattributed_count'=>$unattributed,'won_without_booked_value'=>$won-$known,
            'booked_by_currency'=>$money,
        ];
    }

    /**
     * Current open pipeline allocated by expected close date, not a historical
     * forecast snapshot. Unknown amounts/probabilities remain visible as counts.
     */
    public function forecast(string $org, ?int $owner, ?string $pipeline, string $from, string $to): array
    {
        $this->validate($org,$owner,$pipeline,$from,$to);
        $params=['org'=>$org,'from_at'=>$from,'to_at'=>$to];
        $where=['c.organization_id=:org','c.status IN (\'active\',\'paused\')'];
        if ($owner!==null) {$where[]='c.assigned_user_id=:owner';$params['owner']=$owner;}
        if ($pipeline!==null) {$where[]='c.pipeline_id=:pipeline';$params['pipeline']=$pipeline;}
        $sql = <<<'SQL'
WITH facts AS (
 SELECT CASE WHEN c.expected_close_at IS NULL THEN 'unscheduled'
 WHEN c.expected_close_at<:from_at THEN 'before_period'
 WHEN c.expected_close_at>=:to_at THEN 'after_period' ELSE 'in_period' END bucket,
 CASE WHEN c.deal_value IS NOT NULL AND c.deal_value>=0 AND UPPER(c.currency) IN ('USD','EUR','UAH')
 THEN UPPER(c.currency) ELSE NULL END currency,
 c.deal_value, COALESCE(c.probability,s.probability_default) probability
 FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s
 ON s.id=c.stage_id AND s.organization_id=c.organization_id AND s.pipeline_id=c.pipeline_id
 WHERE
SQL;
        $sql .= ' '.implode(' AND ',$where). <<<'SQL'

)
SELECT bucket,currency,COUNT(*) deal_count,
 CASE WHEN currency IS NOT NULL THEN CAST(SUM(deal_value) AS DECIMAL(38,2)) ELSE NULL END pipeline_value,
 SUM(CASE WHEN currency IS NOT NULL AND probability BETWEEN 0 AND 100 THEN 1 ELSE 0 END) weighted_count,
 CASE WHEN currency IS NOT NULL THEN
 CAST(ROUND(SUM(CASE WHEN probability BETWEEN 0 AND 100 THEN deal_value*probability/100 ELSE NULL END),2) AS DECIMAL(38,2))
 ELSE NULL END weighted_value
FROM facts GROUP BY bucket,currency ORDER BY bucket,currency
SQL;
        $buckets=[];
        foreach (['before_period','in_period','after_period','unscheduled'] as $bucket) {
            $buckets[$bucket]=['bucket'=>$bucket,'deal_count'=>0,'unknown_value_count'=>0,'money_by_currency'=>[]];
        }
        foreach ($this->rows($sql,$params) as $row) {
            $bucket=$row['bucket'];$count=(int)$row['deal_count'];
            $buckets[$bucket]['deal_count']+=$count;
            if ($row['currency']===null) {
                $buckets[$bucket]['unknown_value_count']+=$count;
            } else {
                $buckets[$bucket]['money_by_currency'][]=[
                    'currency'=>$row['currency'],'deal_count'=>$count,'pipeline_value'=>(string)$row['pipeline_value'],
                    'weighted_count'=>(int)$row['weighted_count'],
                    'weighted_value'=>$row['weighted_value']===null?null:(string)$row['weighted_value'],
                ];
            }
        }
        return ['from'=>$from,'to_exclusive'=>$to,'owner_id'=>$owner,'pipeline_id'=>$pipeline,
            'basis'=>'current_open_pipeline','buckets'=>array_values($buckets)];
    }

    private function validate(string $org,?int $owner,?string $pipeline,string $from,string $to):void
    {
        if ($org===''||($owner!==null&&$owner<1)
            ||($pipeline!==null&&preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,79}$/D',$pipeline)!==1)) {
            throw new DomainException('Invalid Sales analytics scope.');
        }
        $utc=new DateTimeZone('UTC');
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$from,$utc);
        $end=DateTimeImmutable::createFromFormat('!Y-m-d',$to,$utc);
        if ($start===false||$end===false||$start->format('Y-m-d')!==$from||$end->format('Y-m-d')!==$to
            ||$end<=$start||$start->diff($end)->days>366) {
            throw new DomainException('Sales analytics requires an exclusive date range of 1–366 days.');
        }
    }

    private function rows(string $sql,array $params):array
    {
        $query=$this->connection->prepare($sql);
        $query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
