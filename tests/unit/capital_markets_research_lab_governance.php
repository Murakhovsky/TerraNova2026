<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Domain\Research\ExperimentLifecyclePolicy;
use Domains\CapitalMarkets\Domain\Research\StrategyPromotionGate;

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$experiment=new ExperimentLifecyclePolicy();
foreach([
    ['DRAFT','QUEUED'],
    ['QUEUED','RUNNING'],
    ['RUNNING','COMPLETED'],
    ['RUNNING','FAILED'],
    ['RUNNING','CANCELLED'],
] as [$from,$to])$experiment->assertTransition($from,$to);

$blocked=false;
try{$experiment->assertTransition('COMPLETED','RUNNING');}
catch(InvalidArgumentException){$blocked=true;}
$assert($blocked,'Completed experiment must not return to RUNNING.');

$gate=new StrategyPromotionGate();
$empty=$gate->evaluate('OOS','PAPER',[],[]);
$assert(($empty['status']??null)==='FAILED','Promotion gate must fail closed on empty policy.');

$unsafeLive=$gate->evaluate('PAPER','LIMITED_LIVE',['sample'=>100],['sample'=>['min'=>1]]);
$assert(($unsafeLive['status']??null)==='FAILED','LIMITED_LIVE must require manual review.');

$manual=$gate->evaluate('PAPER','LIMITED_LIVE',['sample'=>100],[
    'sample'=>['min'=>1],
    'manual_review_required'=>true,
]);
$assert(($manual['status']??null)==='MANUAL_REVIEW_REQUIRED','LIMITED_LIVE must stop at human gate.');

$root=dirname(__DIR__,2);
$backtest=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/ResearchBacktestService.php');
foreach([
    "dataset_snapshot_hash",
    "parameters_hash",
    "success_criteria_hash",
    "failure_criteria_hash",
    "hash('sha256'",
    "reproducibility_fingerprint']=$this->fingerprint",
] as $needle){
    $assert(str_contains($backtest,$needle),'Server-side reproducibility contract missing: '.$needle);
}
$assert(!str_contains($backtest,"'reproducibility_fingerprint' as $required"),'Client fingerprint must not be a required field.');

$lab=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/ResearchLabService.php');
foreach([
    'Experiment requires an existing research hypothesis.',
    'Experiment requires an existing strategy version.',
    'Scorecard requires an existing strategy version.',
    'transitionExperiment(',
] as $needle){
    $assert(str_contains($lab,$needle),'Research referential/lifecycle guard missing: '.$needle);
}

echo "Capital Markets Research Lab governance tests passed.\n";
