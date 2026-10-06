<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Research\HypothesisResearchEngine;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$engine=new HypothesisResearchEngine();

$unobservable=[];
for($i=0;$i<100;$i++)$unobservable[]=['stage'=>'SCAN','observable'=>false,'reason'=>'STALE'];
$summary=$engine->summarize($unobservable,30,10);
$assert($summary['verdict']==='INSUFFICIENT_DATA','Unobservable scans must not satisfy the sample threshold.');
$assert($summary['sample']['scan_count']===0,'Unobservable scans leaked into observable sample.');
$assert($summary['sample']['unobservable_scan_count']===100,'Unobservable scan count drifted.');

$noDetected=[];
for($i=0;$i<30;$i++)$noDetected[]=['stage'=>'SCAN','observable'=>true,'detected'=>false];
$summary=$engine->summarize($noDetected,30,10);
$assert($summary['verdict']==='NO_EDGE','Sufficient observable scans without detections must reject the hypothesis.');

$notExecutable=[];
for($i=0;$i<30;$i++){
    $notExecutable[]=['stage'=>'SCAN','observable'=>true,'detected'=>true];
    $notExecutable[]=['stage'=>'EVALUATION','detected'=>true,'executable'=>false,'expected_pnl'=>'3'];
}
$summary=$engine->summarize($notExecutable,30,10);
$assert($summary['verdict']==='EDGE_EXISTS_BUT_NOT_EXECUTABLE','Positive theoretical edge without executable cases must be classified explicitly.');

$paperRequired=[];
for($i=0;$i<30;$i++){
    $paperRequired[]=['stage'=>'SCAN','observable'=>true,'detected'=>true];
    $paperRequired[]=['stage'=>'EVALUATION','detected'=>true,'executable'=>true,'expected_pnl'=>'2'];
}
for($i=0;$i<4;$i++)$paperRequired[]=['stage'=>'EXECUTION','realized'=>true,'realized_pnl'=>'1'];
$summary=$engine->summarize($paperRequired,30,10);
$assert($summary['verdict']==='PAPER_VALIDATION_REQUIRED','Executable edge needs enough execution attempts.');

$edge=[];
for($i=0;$i<30;$i++){
    $edge[]=['stage'=>'SCAN','observable'=>true,'detected'=>true];
    $edge[]=['stage'=>'EVALUATION','detected'=>true,'executable'=>true,'expected_pnl'=>'2'];
}
for($i=0;$i<10;$i++){
    $edge[]=['stage'=>'EXECUTION','realized'=>true,'realized_pnl'=>$i<7?'1':'-0.5'];
}
$summary=$engine->summarize($edge,30,10);
$assert($summary['verdict']==='EDGE_EXISTS','Sufficient positive full-attempt sample should produce EDGE_EXISTS.');
$assert($summary['edge_funnel']['attempted']===10,'Execution attempt funnel count drifted.');
$assert($summary['edge_funnel']['realized']===10,'Realized funnel count drifted.');
$assert($summary['economics']['realized_pnl_total']==='5.5','Realized P&L aggregation drifted.');

$survivorship=[];
for($i=0;$i<30;$i++){
    $survivorship[]=['stage'=>'SCAN','observable'=>true,'detected'=>true];
    $survivorship[]=['stage'=>'EVALUATION','detected'=>true,'executable'=>true,'expected_pnl'=>'2'];
}
for($i=0;$i<10;$i++)$survivorship[]=['stage'=>'EXECUTION','realized'=>true,'realized_pnl'=>'1'];
for($i=0;$i<50;$i++)$survivorship[]=['stage'=>'EXECUTION','realized'=>false,'realized_pnl'=>'0','reason'=>'INSUFFICIENT_LIQUIDITY'];
$summary=$engine->summarize($survivorship,30,10);
$assert($summary['verdict']==='NO_EDGE','Ten successful fills among fifty invalidated attempts must not validate edge.');
$assert($summary['sample']['invalidated_execution_count']===50,'Invalidated execution attempts were lost.');
$assert($summary['economics']['completion_rate']==='0.166666','Completion rate must use the full attempt population.');

$losing=[];
for($i=0;$i<30;$i++){
    $losing[]=['stage'=>'SCAN','observable'=>true,'detected'=>true];
    $losing[]=['stage'=>'EVALUATION','detected'=>true,'executable'=>true,'expected_pnl'=>'2'];
}
for($i=0;$i<10;$i++)$losing[]=['stage'=>'EXECUTION','realized'=>true,'realized_pnl'=>'-1'];
$summary=$engine->summarize($losing,30,10);
$assert($summary['verdict']==='NO_EDGE','Negative realized economics must reject the hypothesis.');

echo "Capital Markets Tokenized Equity research engine passed.\n";
