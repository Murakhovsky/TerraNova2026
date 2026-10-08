<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\PortfolioNavStatementReconciliationPreview;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException($message);
};
$paper=[
    ['venue_id'=>'venue-1','asset_key'=>'USD','available_amount'=>'100.25','reserved_amount'=>'9.75'],
    ['venue_id'=>'venue-2','asset_key'=>'USD','available_amount'=>'50','reserved_amount'=>'0'],
];
$observation=static fn(string $kind,string $id,string $value,?string $venue=null,string $currency='USD'):array => [
    'kind'=>$kind,'evidence_id'=>$id,'amount'=>$value,'venue_id'=>$venue,
    'currency'=>$currency,'status'=>'PENDING_RECONCILIATION','reconciled'=>false,
    'source_key_sha256'=>hash('sha256','source:'.$id),
    'source_document_sha256'=>hash('sha256','file:'.$id),
    'effective_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
];
$source=[
    $observation('VENUE_BALANCE','venue-1-proof','110','venue-1'),
    $observation('VENUE_BALANCE','venue-2-proof','51','venue-2'),
    $observation('EXTERNAL_CASH_FLOW','deposit-1','25'),
    $observation('EXTERNAL_CASH_FLOW','withdrawal-1','-5'),
    $observation('LIABILITY_BALANCE','loan-1','0'),
];
$preview=PortfolioNavStatementReconciliationPreview::inspect($paper,$source,'USD');
$assert($preview['status']==='PENDING_RECONCILIATION','A matched amount is not independent accounting approval.');
$assert($preview['eligible_for_nav']===false && $preview['financial_authority']==='OBSERVATION_ONLY','Preview cannot mint NAV authority.');
$assert($preview['venue_comparison'][0]['same_amount']===true,'Internal cash must include reserved funds.');
$assert($preview['venue_comparison'][1]['difference']==='1','Venue cash differences must use exact Decimal.');
$assert(in_array('VENUE_CASH_MISMATCH',$preview['issues'],true),'Venue cash mismatch must be a blocker.');
$assert($preview['candidate_cumulative_external_flow']==='20','Signed external flow candidate must use Decimal arithmetic.');
$assert($preview['candidate_liability_balance']==='0','Explicit zero liability evidence is different from absent evidence.');
$assert($preview['source_counts']['VENUE_BALANCE']===2,'Source evidence counts must be retained.');

$missing=PortfolioNavStatementReconciliationPreview::inspect($paper,[$source[0]],'USD');
$assert(in_array('VENUE_STATEMENT_COVERAGE_INCOMPLETE',$missing['issues'],true),'Absent venue statements must block coverage.');
$assert(in_array('EXTERNAL_FLOW_HISTORY_MISSING',$missing['issues'],true),'No flows must not be assumed to mean zero.');
$assert($missing['candidate_liability_balance']===null,'Missing liability proof must not become a zero liability.');
$assert($missing['candidate_cumulative_external_flow']===null,'Missing cash-flow history must not become a zero flow.');

$currency=$source;$currency[0]['currency']='EUR';
$fx=PortfolioNavStatementReconciliationPreview::inspect($paper,$currency,'USD');
$assert(in_array('SOURCE_CURRENCY_UNCONVERTED',$fx['issues'],true),'FX conversion is forbidden without a trusted source.');

$duplicate=$source;$duplicate[]=$observation('VENUE_BALANCE','venue-1-second','110','venue-1');
$dupe=PortfolioNavStatementReconciliationPreview::inspect($paper,$duplicate,'USD');
$assert(in_array('DUPLICATE_VENUE_STATEMENT',$dupe['issues'],true),'Ambiguous venue coverage must fail closed.');

$history=$source;
$historicalVenue=$observation('VENUE_BALANCE','venue-1-old','999','venue-1');
$historicalVenue['effective_at']=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-5 minutes')->format(DATE_ATOM);
$history[]=$historicalVenue;
$historyPreview=PortfolioNavStatementReconciliationPreview::inspect($paper,$history,'USD');
$assert($historyPreview['venue_comparison'][0]['same_amount']===true,
    'An earlier venue balance is historical, not another current balance to sum.');
$assert($historyPreview['historical_venue_statements_ignored']===1,
    'The reconciler must report ignored historical venue statement count.');
$assert(!in_array('DUPLICATE_VENUE_STATEMENT',$historyPreview['issues'],true),
    'Different-time venue statements must not be a duplicate-current-statement error.');

$duplicateReference=$source;$duplicateReference[]=$source[2];
$dupe=PortfolioNavStatementReconciliationPreview::inspect($paper,$duplicateReference,'USD');
$assert(in_array('DUPLICATE_SOURCE_REFERENCE',$dupe['issues'],true),'Repeated financial source reference is not new evidence.');

$badFlow=$source;$badFlow[2]['amount']='not-a-decimal';
$invalidFlow=PortfolioNavStatementReconciliationPreview::inspect($paper,$badFlow,'USD');
$assert($invalidFlow['candidate_cumulative_external_flow']===null,
    'Invalid flow evidence must not silently contribute zero to candidate cash movements.');

$badLiability=$source;$badLiability[4]['source_document_sha256']='invalid';
$invalidLiability=PortfolioNavStatementReconciliationPreview::inspect($paper,$badLiability,'USD');
$assert($invalidLiability['candidate_liability_balance']===null,
    'Unverified liability statement must not imply zero debt.');

$repeatedFlow=$source;$repeatedFlow[]=$source[2];
$duplicateFlow=PortfolioNavStatementReconciliationPreview::inspect($paper,$repeatedFlow,'USD');
$assert($duplicateFlow['candidate_cumulative_external_flow']===null,
    'Duplicate external movement source must suppress the candidate aggregate.');

$unknownAsset=[...$paper,['venue_id'=>'venue-1','asset_key'=>'BTC','available_amount'=>'0.5','reserved_amount'=>'0']];
$assets=PortfolioNavStatementReconciliationPreview::inspect($unknownAsset,$source,'USD');
$assert(in_array('NONCASH_ASSET_VALUATION_REQUIRED',$assets['issues'],true),'Non-cash balances cannot disappear from NAV.');

$badAuthority=$source;$badAuthority[0]['status']='RECONCILED';$badAuthority[0]['reconciled']=true;
$authority=PortfolioNavStatementReconciliationPreview::inspect($paper,$badAuthority,'USD');
$assert(in_array('SOURCE_AUTHORITY_UNEXPECTED',$authority['issues'],true),'An unapproved write cannot grant its own reconciliation authority.');

echo "Capital Markets NAV statement reconciliation preview passed.\n";
