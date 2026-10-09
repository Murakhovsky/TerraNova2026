<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\PortfolioNavSpotMarkEvidence;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $msg):void { if(!$ok) throw new RuntimeException($msg); };
$at=new DateTimeImmutable('2026-10-08T12:00:00Z');
$position=[
    'position_id'=>'pos-1','instrument_id'=>'asset-1','venue_id'=>'venue-1',
    'instrument_kind'=>'SPOT','side'=>'LONG','quantity'=>'3.25',
];
$market=[
    'quality_status'=>'TRUSTED','mode'=>'LIVE','market_status'=>'OPEN',
    'quality_flags'=>[],'source_timestamp'=>'2026-10-08T11:59:50Z',
    'state_version'=>5,'last_event_fingerprint'=>str_repeat('a',64),
    'best_quote'=>[
        'bid_price'=>['quote_asset'=>'USD','value'=>'9.00'],
        'ask_price'=>['quote_asset'=>'USD','value'=>'11.00'],
        'mid_price'=>'10',
    ],
];
$good=PortfolioNavSpotMarkEvidence::inspect($position,$market,'USD',$at);
$assert($good['status']==='CANDIDATE' && $good['candidate_market_value']==='32.5','Spot quantity multiplied by trusted mid must use Decimal.');
$assert($good['mark_reconciled']===false,'Candidate must never be asserted as an approved NAV mark.');
$p=$position;$p['instrument_kind']='PERPETUAL';
$derivative=PortfolioNavSpotMarkEvidence::inspect($p,$market,'USD',$at);
$assert($derivative['reason']==='DERIVATIVE_OR_UNCLASSIFIED_POSITION_REQUIRES_MARGIN_ACCOUNTING','Derivative should not be valued as a cash asset.');
$p=$position;$p['side']='SHORT';
$assert(PortfolioNavSpotMarkEvidence::inspect($p,$market,'USD',$at)['status']==='BLOCKED','Short exposure needs liability accounting.');
$m=$market;$m['quality_status']='DEGRADED';
$assert(PortfolioNavSpotMarkEvidence::inspect($position,$m,'USD',$at)['status']==='BLOCKED','Degraded marks cannot value NAV.');
$m=$market;$m['source_timestamp']='2026-10-08T11:55:00Z';
$assert(PortfolioNavSpotMarkEvidence::inspect($position,$m,'USD',$at)['reason']==='MARK_STALE_OR_FUTURE','Stale market evidence must be rejected.');
$m=$market;$m['best_quote']['ask_price']['quote_asset']='EUR';
$assert(PortfolioNavSpotMarkEvidence::inspect($position,$m,'USD',$at)['reason']==='MARK_CURRENCY_OR_MID_UNVERIFIED','Quote unit mismatch must block valuation.');
$m=$market;$m['quality_flags']=['CLOCK_UNCERTAIN'];
$assert(PortfolioNavSpotMarkEvidence::inspect($position,$m,'USD',$at)['status']==='BLOCKED','Flagged market data must be rejected.');
$m=$market;$m['last_event_fingerprint']='not-a-sha';
$assert(PortfolioNavSpotMarkEvidence::inspect($position,$m,'USD',$at)['status']==='BLOCKED','Missing source provenance must block valuation.');
$p=$position;$p['quantity']='3.2abc';
$assert(PortfolioNavSpotMarkEvidence::inspect($p,$market,'USD',$at)['status']==='BLOCKED','Invalid quantities cannot enter NAV.');
echo "Capital Markets spot-mark NAV evidence tests passed.\n";
