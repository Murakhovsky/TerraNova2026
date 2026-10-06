<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Research\HypothesisVerdict;
use Domains\CapitalMarkets\Domain\Service\HypothesisResearchPolicy;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$policy=new HypothesisResearchPolicy();
$base=[
    'observation_count'=>0,
    'detected_count'=>0,
    'executable_count'=>0,
    'realized_count'=>0,
    'total_realized_pnl'=>'0',
    'average_edge_capture_ratio'=>'0',
];

$assert($policy->verdict($base,30,10)===HypothesisVerdict::InsufficientSample,'Small sample must not produce a research conclusion.');

$noEdge=[...$base,'observation_count'=>30];
$assert($policy->verdict($noEdge,30,10)===HypothesisVerdict::EdgeNotObserved,'No detected edge after sufficient observations must be explicit.');

$notExecutable=[...$base,'observation_count'=>30,'detected_count'=>8];
$assert($policy->verdict($notExecutable,30,10)===HypothesisVerdict::EdgeObservedNotExecutable,'Detected but non-executable edge must be distinguished.');

$unvalidated=[...$base,'observation_count'=>30,'detected_count'=>20,'executable_count'=>12,'realized_count'=>4];
$assert($policy->verdict($unvalidated,30,10)===HypothesisVerdict::EdgeExecutableUnvalidated,'Executable edge needs enough realized samples.');

$validated=[
    ...$base,
    'observation_count'=>50,'detected_count'=>35,'executable_count'=>20,'realized_count'=>12,
    'total_realized_pnl'=>'42.5','average_edge_capture_ratio'=>'0.61',
];
$assert($policy->verdict($validated,30,10)===HypothesisVerdict::EdgeValidated,'Positive realized evidence should validate edge after thresholds.');

$failed=[...$validated,'total_realized_pnl'=>'-1.25'];
$assert($policy->verdict($failed,30,10)===HypothesisVerdict::EdgeNotValidated,'Sufficient but losing evidence must fail validation.');

echo "Capital Markets Tokenized Equity research policy passed.\n";
