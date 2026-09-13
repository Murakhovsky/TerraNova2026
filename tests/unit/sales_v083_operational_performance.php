<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Application\Contract\SalesOperationalPerformanceReadModelInterface;
use Domains\Sales\Application\Service\SalesOperationalPerformanceService;

$readModel = new class implements SalesOperationalPerformanceReadModelInterface {
    public function communicationTimeline(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        return [
            ['deal_id'=>'1','channel'=>'EMAIL','direction'=>'INBOUND','occurred_at'=>'2026-09-01 09:00:00','owner_id'=>10,'pre_period'=>false],
            ['deal_id'=>'1','channel'=>'EMAIL','direction'=>'INBOUND','occurred_at'=>'2026-09-01 09:05:00','owner_id'=>10,'pre_period'=>false],
            ['deal_id'=>'1','channel'=>'EMAIL','direction'=>'OUTBOUND','occurred_at'=>'2026-09-01 09:20:00','owner_id'=>10,'pre_period'=>false],
            ['deal_id'=>'2','channel'=>'PHONE','direction'=>'INBOUND','occurred_at'=>'2026-09-02 10:00:00','owner_id'=>null,'pre_period'=>false],
            ['deal_id'=>'3','channel'=>'WEB','direction'=>'INBOUND','occurred_at'=>'2026-08-31 23:55:00','owner_id'=>11,'pre_period'=>true],
            ['deal_id'=>'3','channel'=>'WEB','direction'=>'OUTBOUND','occurred_at'=>'2026-09-01 00:10:00','owner_id'=>11,'pre_period'=>false],
        ];
    }

    public function followupFacts(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        return [
            ['deal_id'=>'1','owner_id'=>10,'due_at'=>'2026-09-03 12:00:00','completed_at'=>'2026-09-03 11:00:00'],
            ['deal_id'=>'1','owner_id'=>10,'due_at'=>'2026-09-04 12:00:00','completed_at'=>'2026-09-04 13:00:00'],
            ['deal_id'=>'2','owner_id'=>null,'due_at'=>'2026-09-05 12:00:00','completed_at'=>null],
        ];
    }

    public function terminalOutcomeFacts(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $pipelineId = null): array
    {
        return [
            ['type'=>'sales.deal.won','deal_id'=>'1','owner_id'=>10,'lost_reason_id'=>null,'deal_value'=>1000.0,'currency'=>'EUR'],
            ['type'=>'sales.deal.lost','deal_id'=>'2','owner_id'=>10,'lost_reason_id'=>'PRICE','deal_value'=>500.0,'currency'=>'EUR'],
            ['type'=>'sales.deal.lost','deal_id'=>'4','owner_id'=>null,'lost_reason_id'=>'TIMING','deal_value'=>700.0,'currency'=>'USD'],
            ['type'=>'sales.deal.lost','deal_id'=>'5','owner_id'=>11,'lost_reason_id'=>null,'deal_value'=>null,'currency'=>null],
        ];
    }
};

$report = (new SalesOperationalPerformanceService($readModel))->report(
    'org-1',
    new DateTimeImmutable('2026-09-01 00:00:00'),
    new DateTimeImmutable('2026-09-10 00:00:00'),
);

if ($report['response']['opportunities'] !== 2) throw new RuntimeException('Response opportunities must use unanswered inbound bursts and ignore pre-period bursts.');
if ($report['response']['responded'] !== 1 || $report['response']['unanswered'] !== 1) throw new RuntimeException('Response status mismatch.');
if ($report['response']['median_seconds'] !== 1200.0) throw new RuntimeException('Response time must start at the first inbound in the burst.');
if (($report['response']['attribution']['coverage'] ?? null) !== 0.5) throw new RuntimeException('Response attribution coverage mismatch.');
if ($report['followup']['due'] !== 3 || $report['followup']['on_time'] !== 1 || $report['followup']['late'] !== 1 || $report['followup']['overdue'] !== 1) throw new RuntimeException('Follow-up compliance classification mismatch.');
if (($report['followup']['attribution']['coverage'] ?? null) !== (2 / 3)) throw new RuntimeException('Follow-up attribution coverage mismatch.');
if ($report['loss']['by_reason'] !== ['PRICE'=>1,'TIMING'=>1,'UNSPECIFIED'=>1]) throw new RuntimeException('Loss reason grouping mismatch.');
if (($report['loss']['lost_value_by_reason_currency']['PRICE']['EUR'] ?? null) !== 500.0) throw new RuntimeException('EUR lost value mismatch.');
if (($report['loss']['lost_value_by_reason_currency']['TIMING']['USD'] ?? null) !== 700.0) throw new RuntimeException('USD lost value mismatch.');
if (($report['loss']['monetary_snapshot_coverage']['coverage'] ?? null) !== (2 / 3)) throw new RuntimeException('Loss monetary snapshot coverage mismatch.');

$managers = [];
foreach ($report['manager_performance']['managers'] as $manager) $managers[(int) $manager['owner_id']] = $manager;
if (($managers[10]['outcomes']['win_rate_closed'] ?? null) !== 0.5) throw new RuntimeException('Manager closed win rate must use attributed terminal outcomes only.');
if (($report['manager_performance']['attribution_coverage']['outcome']['coverage'] ?? null) !== 0.75) throw new RuntimeException('Outcome attribution coverage mismatch.');

echo "Sales V0.8.3 operational performance: OK\n";
