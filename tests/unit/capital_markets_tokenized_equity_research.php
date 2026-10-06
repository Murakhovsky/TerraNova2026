<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Research\HypothesisResearchEngine;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$engine=new HypothesisResearchEngine();

$insufficient=[];
for($i=0;$i<5;$i++){
    $insufficient[]=['stage'=>'SCAN'];
    $insufficient[]=['stage'=>'EVALUATION','detected'=>true,'executable'=>true,'expected_pnl'=>'2'];
}
$summary=$engine->summarize($insufficient,10,3);
$assert($summary['verdict']==='INSUFFICIENT_DATA','Small research sample must not produce a confident verdict.');

$notExecutable=[];
for($i=0;$i<30;$i++){
    $notExecutable[]=['stage'=>'SCAN'];
    $notExecutable[]=['stage'=>'EVALUATION','detected'=>true,'executable'=>false,'expected_pnl'=>'3'];
}
$summary=$engine->summarize($notExecutable,30,10);
$assert($summary['verdict']==='EDGE_EXISTS_BUT_NOT_EXECUTABLE','Positive theoretical edge without executable cases must be classified explicitly.');

$edge=[];
for($i=0;$i<30;$i++){
    $edge[]=['stage'=>'SCAN'];
    $edge[]=['stage'=>'EVALUATION','detected'=>true,'executable'=>true,'expected_pnl'=>'2'];
}
for($i=0;$i<10;$i++){
    $edge[]=['stage'=>'EXECUTION','realized'=>true,'realized_pnl'=>$i<7?'1':'-0.5'];
}
$summary=$engine->summarize($edge,30,10);
$assert($summary['verdict']==='EDGE_EXISTS','Sufficient positive realized sample should produce EDGE_EXISTS.');
$assert($summary['edge_funnel']['realized']===10,'Realized funnel count drifted.');
$assert($summary['economics']['realized_pnl_total']==='5.5','Realized P&L aggregation drifted.');

$noEdge=[];
for($i=0;$i<30;$i++){
    $noEdge[]=['stage'=>'SCAN'];
    $noEdge[]=['stage'=>'EVALUATION','detected'=>true,'executable'=>true,'expected_pnl'=>'-1'];
}
$summary=$engine->summarize($noEdge,30,10);
$assert($summary['verdict']==='NO_EDGE','Negative expected economics must reject the hypothesis.');

echo "Capital Markets Tokenized Equity research engine passed.\n";
