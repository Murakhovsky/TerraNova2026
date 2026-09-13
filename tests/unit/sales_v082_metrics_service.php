<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Application\Contract\SalesHistoricalMetricsReadModelInterface;
use Domains\Sales\Application\Service\SalesHistoricalMetricsService;

$readModel = new class implements SalesHistoricalMetricsReadModelInterface {
    public function pipelineMoney(string $organizationId, ?string $pipelineId = null): array
    {
        return [
            ['currency' => 'EUR', 'deal_count' => 2, 'pipeline_value' => 100000.0, 'weighted_pipeline' => 50000.0],
            ['currency' => 'USD', 'deal_count' => 1, 'pipeline_value' => 80000.0, 'weighted_pipeline' => 60000.0],
        ];
    }
    public function closedOutcomes(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array { return ['won' => 2, 'closed' => 5]; }
    public function createdCohortOutcomes(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array { return ['won' => 1, 'created' => 10]; }
    public function transitionFlow(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        return [
            ['pipeline_id' => 'p1', 'from_stage_code' => 'NEW', 'to_stage_code' => 'QUALIFIED', 'history_quality' => 'COMPLETE', 'transition_count' => 3],
            ['pipeline_id' => 'p1', 'from_stage_code' => 'NEW', 'to_stage_code' => 'LOST', 'history_quality' => 'PARTIAL', 'transition_count' => 1],
        ];
    }
    public function cohortFunnel(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        return ['created' => 10, 'stages' => [
            ['pipeline_id' => 'p1', 'stage_code' => 'NEW', 'history_quality' => 'COMPLETE', 'reached_count' => 10],
            ['pipeline_id' => 'p1', 'stage_code' => 'QUALIFIED', 'history_quality' => 'COMPLETE', 'reached_count' => 6],
        ]];
    }
    public function stageDurationSamples(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        return array_map(static fn (int $seconds): array => [
            'pipeline_id' => 'p1', 'stage_code' => 'NEW', 'history_quality' => 'COMPLETE', 'duration_seconds' => $seconds,
        ], [100, 200, 300, 1000]);
    }
    public function salesCycleSamples(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        return [
            ['pipeline_id' => 'p1', 'deal_id' => '1', 'won_at' => '2026-08-01 10:00:00', 'cycle_seconds' => 10],
            ['pipeline_id' => 'p1', 'deal_id' => '2', 'won_at' => '2026-08-02 10:00:00', 'cycle_seconds' => 20],
            ['pipeline_id' => 'p1', 'deal_id' => '3', 'won_at' => '2026-09-02 10:00:00', 'cycle_seconds' => 30],
        ];
    }
    public function openDealRiskFacts(string $organizationId, DateTimeImmutable $asOf, ?string $pipelineId = null): array
    {
        return [
            ['deal_id' => '1','pipeline_id'=>'p1','stage_id'=>'s1','stage_code'=>'NEW','deal_value'=>100.0,'currency'=>'EUR','last_activity_at'=>'2026-09-01 00:00:00','next_contact_at'=>null,'expected_close_at'=>null,'entered_at'=>'2026-09-01 00:00:00','history_quality'=>'COMPLETE','stuck_after_seconds'=>86400],
            ['deal_id' => '2','pipeline_id'=>'p1','stage_id'=>'s1','stage_code'=>'NEW','deal_value'=>200.0,'currency'=>'USD','last_activity_at'=>'2026-09-13 12:00:00','next_contact_at'=>'2026-09-10 00:00:00','expected_close_at'=>null,'entered_at'=>'2026-09-01 00:00:00','history_quality'=>'COMPLETE','stuck_after_seconds'=>86400],
        ];
    }
};

$metrics = (new SalesHistoricalMetricsService($readModel))->metrics(
    'org-1', new DateTimeImmutable('2026-08-01 00:00:00'), new DateTimeImmutable('2026-09-14 00:00:00')
);
if ($metrics['win_rate_closed']['rate'] !== 0.4) throw new RuntimeException('Closed win rate mismatch.');
if ($metrics['win_rate_created']['rate'] !== 0.1) throw new RuntimeException('Created-cohort win rate mismatch.');
if (($metrics['stage_conversion'][0]['conversion_rate'] ?? null) !== 0.75) throw new RuntimeException('Stage conversion denominator must be source-stage exits.');
if (($metrics['cohort_funnel']['stages'][1]['reach_rate'] ?? null) !== 0.6) throw new RuntimeException('Cohort reach rate mismatch.');
if (($metrics['stage_duration'][0]['median_seconds'] ?? null) !== 250.0) throw new RuntimeException('Stage duration median mismatch.');
if (($metrics['stage_duration'][0]['p75_seconds'] ?? null) !== 475.0) throw new RuntimeException('Stage duration p75 mismatch.');
if (($metrics['sales_cycle']['median_seconds'] ?? null) !== 20.0) throw new RuntimeException('Sales cycle median mismatch.');
if (count($metrics['sales_cycle']['trend']) !== 2) throw new RuntimeException('Sales cycle trend must preserve month buckets.');
if (count($metrics['stuck_deals']) !== 1) throw new RuntimeException('Only threshold + inactivity + no-future-contact Deal may be stuck.');
$currencies = array_column($metrics['at_risk_revenue'], 'currency');
if ($currencies !== ['EUR', 'USD']) throw new RuntimeException('At-risk revenue must stay currency-separated.');
if (count($metrics['pipeline_value']) !== 2) throw new RuntimeException('Pipeline monetary totals must stay currency-separated.');

echo "Sales V0.8.2 metrics service: OK\n";
