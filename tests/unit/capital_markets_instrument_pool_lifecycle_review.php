<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Application\Service\InstrumentPoolLifecycleReviewer;
require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$p=new InstrumentPoolLifecycleReviewer();
$base=[
    'health'=>'AVAILABLE','truncated'=>false,'provider'=>'BINANCE','venue_id'=>'v',
    'rows'=>[
        ['venue_symbol'=>'BTCUSDT','instrument_id'=>'btc','observation'=>'MAPPED_ACTIVE_OBSERVED'],
        ['venue_symbol'=>'AAPLBUSDT','instrument_id'=>'token-b','observation'=>'MAPPED_NOT_OBSERVED_REVIEW'],
        ['venue_symbol'=>'AAPLXUSDT','instrument_id'=>null,'observation'=>'UNMAPPED_REVIEW'],
    ],
];
$one=$p->review([$base]);
$assert($one['status']==='READ_ONLY_REVIEW'
    &&count($one['rows'])===2
    &&$one['rows'][0]['decision']==='AWAIT_MORE_EVIDENCE',
    'First observation must never trigger a lifecycle change.');
$three=$p->review([$base,$base,$base]);
$assert(count($three['rows'])===2
    &&$three['rows'][0]['decision']==='OPERATOR_REVIEW_REQUIRED'
    &&$three['rows'][1]['consecutive_healthy_observations']===3
    &&$three['auto_delist']===false&&$three['executable']===false,
    'Repeated observations should require human review, never auto-delist.');
$outage=$base;$outage['health']='UNAVAILABLE';
$halt=$p->review([$base,$outage,$base]);
$assert($halt['rows'][0]['consecutive_healthy_observations']===1,
    'Provider outage incorrectly counted as a healthy observation.');
$truncated=$base;$truncated['truncated']=true;
$assert($p->review([$truncated,$base,$base])['status']==='INSUFFICIENT_EVIDENCE',
    'Incomplete catalog must not establish mapping absence.');
$wrong=$base;$wrong['rows'][1]['instrument_id']='other';
$assert($p->review([$base,$wrong,$base])['rows'][0]['consecutive_healthy_observations']===1,
    'Change in mapping identity did not reset evidence count.');
$other=$base;$other['venue_id']='other';
$assert($p->review([$base,$other,$base])['rows'][0]['consecutive_healthy_observations']===1,
    'Evidence crossed venue boundary.');
$assert($p->review([])['status']==='NO_EVIDENCE','No history must not appear healthy.');
echo "Instrument Pool conservative lifecycle review passed.\n";
