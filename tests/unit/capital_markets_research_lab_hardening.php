<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Domain\Research\ParameterSensitivityEngine;
use Domains\CapitalMarkets\Domain\Research\ReplayDataGuard;
use Domains\CapitalMarkets\Domain\Research\ResearchDuplicateDetector;
use Domains\CapitalMarkets\Domain\Research\ResearchIsolationPolicy;
use Domains\CapitalMarkets\Domain\Research\ResearchMetricsEngine;
use Domains\CapitalMarkets\Domain\Research\WalkForwardEngine;

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$root=dirname(__DIR__,2);

$metrics=(new ResearchMetricsEngine())->calculate([
    ['expected_net_pnl'=>'10'],
    ['expected_net_pnl'=>'-20'],
    ['expected_net_pnl'=>'15'],
]);
$assert(($metrics['risk']['max_drawdown']??null)==='20','Drawdown must preserve chronological P&L order.');
$assert(($metrics['risk']['worst_observation']??null)==='-20','Worst observation must use sorted copy only.');

$guard=new ReplayDataGuard();
$futureBlocked=false;
try{
    $guard->assertAvailableAt(
        new DateTimeImmutable('2026-01-01T10:00:00Z'),
        new DateTimeImmutable('2026-01-01T10:00:01Z'),
        'future snapshot'
    );
}catch(InvalidArgumentException){$futureBlocked=true;}
$assert($futureBlocked,'Look-ahead data must be blocked.');

$costBlocked=false;
try{$guard->assertTransactionCosts(['fees'=>[]]);}
catch(InvalidArgumentException){$costBlocked=true;}
$assert($costBlocked,'Backtest without slippage must not be promotion-valid.');

$isolation=new ResearchIsolationPolicy();
$oosReuseBlocked=false;
try{
    $isolation->assertNewOosPeriod(
        ['from'=>'2026-01-01','to'=>'2026-02-01'],
        ['from'=>'2026-01-01','to'=>'2026-02-01'],
    );
}catch(InvalidArgumentException){$oosReuseBlocked=true;}
$assert($oosReuseBlocked,'Failed/used OOS period must not be reused.');

$sensitivity=(new ParameterSensitivityEngine())->analyze([
    ['parameter'=>0.1,'score'=>10],
    ['parameter'=>0.2,'score'=>100],
    ['parameter'=>0.3,'score'=>9],
]);
$assert(($sensitivity['warning']??null)==='OVERFIT_RISK','Narrow parameter peak must produce OVERFIT_RISK.');

$duplicates=new ResearchDuplicateDetector();
$matches=$duplicates->find([
    'title'=>'Funding capture on BTC perpetual',
    'description'=>'Capture positive funding with spot hedge',
    'economic_reason'=>'persistent positive funding',
    'expected_behavior'=>'short perp long spot',
    'edge_source'=>'FUNDING',
    'markets'=>['BTC'],
    'venues'=>['BYBIT'],
],[
    [
        'hypothesis_id'=>'h-old',
        'title'=>'BTC funding capture perpetual',
        'description'=>'positive funding captured using spot hedge',
        'economic_reason'=>'persistent funding premium',
        'expected_behavior'=>'long spot short perp',
        'edge_source'=>'FUNDING',
        'markets'=>['BTC'],
        'venues'=>['BYBIT'],
        'status'=>'REJECTED',
    ]
],2500);
$assert($matches!==[],'Rejected prior research must participate in duplicate detection.');

$windows=(new WalkForwardEngine())->windows(
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    new DateTimeImmutable('2026-05-01T00:00:00Z'),
    30,10,10
);
$assert(count($windows)>=2,'Walk-forward must produce multiple train/test windows.');
foreach($windows as $window){
    $assert(new DateTimeImmutable($window['train']['to'])<=new DateTimeImmutable($window['test']['from']),'Walk-forward train/test must not overlap.');
}

$replay=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/RelativeValueHistoricalReplayService.php');
foreach([
    'RelativeValueEconomicsCalculator',
    'RelativeValueOpportunityEvaluator',
    'SpotPerpetualMarketStateFactory',
    'historicalSpotPerp',
    "['H4','H5','H6']",
    'production_economics_reused',
] as $needle){
    $assert(str_contains($replay,$needle),'Shared replay contract missing: '.$needle);
}
$assert(!str_contains($replay,'expectedNetPnl=')&&!str_contains($replay,'expected_net_pnl ='),
    'Replay must not implement parallel P&L math.');

$service=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/ResearchBacktestService.php');
foreach([
    'ResearchExecutionBudgetPolicy',
    'ResearchIsolationPolicy',
    'ResearchMetricsEngine',
    'ResearchConfidenceEngine',
    'ResearchTelemetry',
    'walkForward(',
    'OUT_OF_SAMPLE',
    'assertNewOosPeriod',
] as $needle){
    $assert(str_contains($service,$needle),'Backtest orchestration hardening missing: '.$needle);
}

echo "Capital Markets Research Lab hostile hardening tests passed.\n";
