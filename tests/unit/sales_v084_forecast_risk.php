<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Application\Contract\SalesForecastRiskReadModelInterface;
use Domains\Sales\Application\Service\SalesForecastRiskService;

$readModel = new class implements SalesForecastRiskReadModelInterface {
    public function openDeals(string $organizationId, DateTimeImmutable $asOf, ?string $pipelineId = null): array
    {
        return [
            [
                'deal_id'=>'1','public_id'=>'D-1','title'=>'Exact stuck EUR','pipeline_id'=>'p1','stage_id'=>'s1','stage_code'=>'QUALIFIED','current_owner_id'=>10,
                'deal_value'=>1000.0,'currency'=>'EUR','deal_probability'=>50.0,'stage_probability'=>40.0,
                'last_activity_at'=>'2026-09-01 00:00:00','next_contact_at'=>null,'expected_close_at'=>'2026-09-20 00:00:00',
                'entered_at'=>'2026-09-01 00:00:00','history_quality'=>'COMPLETE','stuck_after_seconds'=>86400,
            ],
            [
                'deal_id'=>'2','public_id'=>'D-2','title'=>'Stage probability USD','pipeline_id'=>'p1','stage_id'=>'s2','stage_code'=>'PROPOSAL','current_owner_id'=>11,
                'deal_value'=>2000.0,'currency'=>'USD','deal_probability'=>null,'stage_probability'=>25.0,
                'last_activity_at'=>'2026-09-12 00:00:00','next_contact_at'=>'2026-09-10 00:00:00','expected_close_at'=>'2026-09-25 00:00:00',
                'entered_at'=>null,'history_quality'=>'ESTIMATED','stuck_after_seconds'=>86400,
            ],
            [
                'deal_id'=>'3','public_id'=>'D-3','title'=>'No probability EUR','pipeline_id'=>'p1','stage_id'=>'s3','stage_code'=>'NEGOTIATION','current_owner_id'=>12,
                'deal_value'=>500.0,'currency'=>'EUR','deal_probability'=>null,'stage_probability'=>null,
                'last_activity_at'=>'2026-09-13 00:00:00','next_contact_at'=>'2026-09-18 00:00:00','expected_close_at'=>'2026-09-30 00:00:00',
                'entered_at'=>'2026-09-12 00:00:00','history_quality'=>'PARTIAL','stuck_after_seconds'=>604800,
            ],
            [
                'deal_id'=>'4','public_id'=>'D-4','title'=>'Unscheduled EUR','pipeline_id'=>'p1','stage_id'=>'s1','stage_code'=>'QUALIFIED','current_owner_id'=>10,
                'deal_value'=>300.0,'currency'=>'EUR','deal_probability'=>70.0,'stage_probability'=>40.0,
                'last_activity_at'=>'2026-09-13 12:00:00','next_contact_at'=>null,'expected_close_at'=>null,
                'entered_at'=>'2026-09-13 12:00:00','history_quality'=>'COMPLETE','stuck_after_seconds'=>86400,
            ],
            [
                'deal_id'=>'5','public_id'=>'D-5','title'=>'Overdue close USD','pipeline_id'=>'p1','stage_id'=>'s2','stage_code'=>'PROPOSAL','current_owner_id'=>11,
                'deal_value'=>700.0,'currency'=>'USD','deal_probability'=>80.0,'stage_probability'=>25.0,
                'last_activity_at'=>'2026-09-13 00:00:00','next_contact_at'=>'2026-09-18 00:00:00','expected_close_at'=>'2026-09-10 00:00:00',
                'entered_at'=>'2026-09-12 00:00:00','history_quality'=>'COMPLETE','stuck_after_seconds'=>86400,
            ],
        ];
    }
};

$report = (new SalesForecastRiskService($readModel))->forecast(
    'org-1',
    new DateTimeImmutable('2026-09-15 00:00:00'),
    new DateTimeImmutable('2026-10-01 00:00:00'),
    new DateTimeImmutable('2026-09-14 00:00:00'),
    'p1',
);

if ($report['formula_version'] !== 'sales-forecast-risk-v0.8.4') throw new RuntimeException('Formula version mismatch.');
$nominal = array_column($report['forecast_value_by_currency'], 'value', 'currency');
if (($nominal['EUR'] ?? null) !== 1500.0 || ($nominal['USD'] ?? null) !== 2000.0) throw new RuntimeException('Forecast nominal values must remain currency-separated.');
$weighted = array_column($report['weighted_forecast_by_currency'], 'value', 'currency');
if (($weighted['EUR'] ?? null) !== 500.0 || ($weighted['USD'] ?? null) !== 500.0) throw new RuntimeException('Weighted forecast must use Deal then stage-default probability and exclude missing probability.');
$atRiskForecast = array_column($report['forecast_at_risk_revenue_by_currency'], 'value', 'currency');
if (($atRiskForecast['EUR'] ?? null) !== 1000.0 || ($atRiskForecast['USD'] ?? null) !== 2000.0) throw new RuntimeException('Forecast at-risk revenue mismatch.');
$currentRisk = array_column($report['current_at_risk_revenue_by_currency'], 'value', 'currency');
if (($currentRisk['EUR'] ?? null) !== 1000.0 || ($currentRisk['USD'] ?? null) !== 2700.0) throw new RuntimeException('Current at-risk revenue mismatch.');
$unscheduled = array_column($report['unscheduled_open_revenue_by_currency'], 'value', 'currency');
if (($unscheduled['EUR'] ?? null) !== 300.0) throw new RuntimeException('Unscheduled open revenue must remain visible.');
if (($report['coverage']['forecast_probability_rate'] ?? null) !== (2 / 3)) throw new RuntimeException('Probability coverage mismatch.');

$deals = array_column($report['deals'], null, 'deal_id');
$codes1 = array_column($deals['1']['risk']['reasons'], 'code');
if ($codes1 !== ['STUCK']) throw new RuntimeException('Exact old stage must be explainably STUCK.');
$codes2 = array_column($deals['2']['risk']['reasons'], 'code');
if ($codes2 !== ['NEXT_CONTACT_OVERDUE']) throw new RuntimeException('ESTIMATED stage age must not fabricate STUCK.');
if (($deals['2']['probability']['source'] ?? null) !== 'STAGE_DEFAULT') throw new RuntimeException('Stage probability fallback must be explicit.');
if (($deals['3']['probability']['valid'] ?? true) !== false || !array_key_exists('weighted_contribution', $deals['3']['forecast']) || $deals['3']['forecast']['weighted_contribution'] !== null) throw new RuntimeException('Missing probability must not be silently treated as zero.');
$codes5 = array_column($deals['5']['risk']['reasons'], 'code');
if ($codes5 !== ['EXPECTED_CLOSE_OVERDUE']) throw new RuntimeException('Past expected close risk mismatch.');
if (($deals['5']['forecast']['in_window'] ?? true) !== false) throw new RuntimeException('Overdue Deal outside forecast window must not contribute current-window forecast.');

echo "Sales V0.8.4 forecast risk: OK\n";
