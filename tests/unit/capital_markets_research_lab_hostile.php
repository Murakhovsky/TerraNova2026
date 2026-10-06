<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Domain\Research\ResearchDuplicateDetector;
use Domains\CapitalMarkets\Domain\Research\ResearchExecutionBudgetPolicy;
use Domains\CapitalMarkets\Domain\Research\StrategyPromotionGate;
use Domains\CapitalMarkets\Domain\Research\WalkForwardEngine;

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$budget=new ResearchExecutionBudgetPolicy();
$blocked=false;
try{
    $budget->assertBacktest([
        'snapshot_limit'=>10000,
        'parameter_combinations'=>100,
        'maximum_compute_units'=>50000,
    ]);
}catch(InvalidArgumentException){
    $blocked=true;
}
$assert($blocked,'Oversized parameter sweep must be blocked by compute budget.');

$walk=new WalkForwardEngine();
$windows=$walk->windows(
    new DateTimeImmutable('2025-01-01T00:00:00Z'),
    new DateTimeImmutable('2025-07-01T00:00:00Z'),
    60,30,30
);
$assert(count($windows)>=3,'Walk-forward must produce multiple sequential windows.');
foreach($windows as $window){
    $assert(new DateTimeImmutable($window['train']['to'])<=new DateTimeImmutable($window['test']['from']),'Walk-forward train/test overlap detected.');
}

$duplicates=(new ResearchDuplicateDetector())->find(
    [
        'title'=>'Cross venue funding differential capture',
        'economic_reason'=>'Persistent funding differences between venues',
        'edge_source'=>'FUNDING',
        'markets'=>['BTC-PERP'],
        'venues'=>['A','B'],
    ],
    [[
        'hypothesis_id'=>'existing-h6',
        'title'=>'Cross venue funding differential capture',
        'economic_reason'=>'Persistent funding differences between venues',
        'edge_source'=>'FUNDING',
        'markets'=>['BTC-PERP'],
        'venues'=>['A','B'],
        'status'=>'REJECTED',
    ]]
);
$assert($duplicates!==[],'Duplicate detector must surface prior rejected research.');

$promotion=new StrategyPromotionGate();
$decision=$promotion->evaluate('OOS','PAPER',[
    'minimum_oos_score'=>80,
    'maximum_drawdown'=>7,
],[
    'minimum_oos_score'=>['min'=>75],
    'maximum_drawdown'=>['max'=>10],
    'manual_review_required'=>true,
]);
$assert($decision['status']==='MANUAL_REVIEW_REQUIRED','Manual promotion gate must never auto-promote.');

echo "Capital Markets Research Lab hostile tests passed.\n";
