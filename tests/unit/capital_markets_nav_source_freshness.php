<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\PortfolioNavFinancialEvidencePolicy;
use Domains\CapitalMarkets\Application\Service\PortfolioNavStatementReconciliationPreview;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert = static function (bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException($message);
};
$now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
$asOf = static fn(DateTimeImmutable $time):string => $time->format(DATE_ATOM);
$make = static function(string $id,string $kind,string $amount,string $at,?string $venue=null):array {
    return PortfolioNavFinancialEvidencePolicy::normalize([
        'evidence_id'=>$id,
        'kind'=>$kind,
        'amount'=>$amount,
        'currency'=>'USD',
        'provider_id'=>'independent-statement-feed',
        'source_reference'=>'reference-'.$id,
        'source_document_sha256'=>hash('sha256','document-'.$id),
        'collected_by'=>'source-ingestion',
        'effective_at'=>$at,
        'venue_id'=>$venue,
        'liability_account_id'=>$kind==='LIABILITY_BALANCE'?'loan-account-1':null,
    ]);
};
$balances=[[
    'venue_id'=>'venue-1',
    'asset_key'=>'USD',
    'available_amount'=>'100',
    'reserved_amount'=>'10',
]];
$cash = $make('cash','VENUE_BALANCE','110',$asOf($now),'venue-1');
$liability = $make('liability','LIABILITY_BALANCE','0',$asOf($now));
$flow = $make('deposit','EXTERNAL_CASH_FLOW','20',$asOf($now->modify('-20 days')));
$all=[$cash,$liability,$flow];

$normal=PortfolioNavStatementReconciliationPreview::inspect($balances,$all,'USD',$now);
$assert($normal['venue_comparison'][0]['same_amount']===true,'Exact cash statement comparison should match.');
$assert($normal['candidate_cumulative_external_flow']==='20','Historical external flows should retain their candidate total.');
$assert($normal['candidate_liability_balance']==='0','Verified zero liabilities remain a candidate, not an assumed default.');
$assert($normal['status']==='PENDING_RECONCILIATION' && $normal['eligible_for_nav']===false,
    'Matching statements are never a self-approval of NAV.');

$stale=$all;
$stale[0]=$make('old-cash','VENUE_BALANCE','110',$asOf($now->modify('-30 minutes')),'venue-1');
$old=PortfolioNavStatementReconciliationPreview::inspect($balances,$stale,'USD',$now);
$assert(in_array('BALANCE_STATEMENT_STALE',$old['issues'],true),
    'A 30-minute-old venue statement must be rejected for current NAV.');
$assert($old['validated_source_counts']['VENUE_BALANCE']===0,
    'Stale venue statements must not count as fresh evidence.');

$staleLiability=$all;
$staleLiability[1]=$make('old-liability','LIABILITY_BALANCE','0',$asOf($now->modify('-1 day')));
$old=PortfolioNavStatementReconciliationPreview::inspect($balances,$staleLiability,'USD',$now);
$assert(in_array('BALANCE_STATEMENT_STALE',$old['issues'],true)
    && $old['candidate_liability_balance']===null,
    'Stale liability evidence must not produce a valid candidate balance.');

$future=$all;
$future[0]['effective_at']=$asOf($now->modify('+1 minute'));
$bad=PortfolioNavStatementReconciliationPreview::inspect($balances,$future,'USD',$now);
$assert(in_array('SOURCE_EFFECTIVE_TIME_FUTURE',$bad['issues'],true),
    'Future-dated source statements cannot pass the accounting preflight.');

$missing=$all;
unset($missing[0]['effective_at']);
$bad=PortfolioNavStatementReconciliationPreview::inspect($balances,$missing,'USD',$now);
$assert(in_array('SOURCE_EFFECTIVE_TIME_MISSING',$bad['issues'],true),
    'Missing source-time provenance must fail closed.');

echo "Capital Markets NAV source-statement freshness acceptance passed.\n";
