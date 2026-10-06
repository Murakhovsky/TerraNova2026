<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\ConversionRate;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use Domains\CapitalMarkets\Domain\Opportunity\Opportunity;
use Domains\CapitalMarkets\Domain\Opportunity\OpportunityStatus;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDetectorConfig;
use Domains\CapitalMarkets\Domain\Service\NetEconomicsEngine;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquityRiskEngine;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquitySpreadDetector;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$eq=new AssetCode('AAPL');
$tok=new AssetCode('AAPLX');
$usd=new AssetCode('USD');
$usdt=new AssetCode('USDT');
$quote=static fn(string $bid,string $ask,AssetCode $base)=>new MarketQuote(
    new Price(Decimal::fromString($bid),$base,$usd,4),new Quantity(Decimal::fromString('20'),$base,8),
    new Price(Decimal::fromString($ask),$base,$usd,4),new Quantity(Decimal::fromString('20'),$base,8),
);
$quality=new MarketDataQualityAssessment(MarketTrustStatus::Trusted,100,[],50,10,60,null);
$sourceTime=new DateTimeImmutable('2026-10-06T12:00:00.400000+00:00');
$now=new DateTimeImmutable('2026-10-06T12:00:00.500000+00:00');

$reference=new ReferenceMarketState(
    InstrumentId::fromString('instrument:aapl'),MarketSourceId::fromString('source:massive'),
    $quote('100','100.1',$eq),MarketSession::Regular,null,null,ReferenceType::Nbbo,100,$quality,$sourceTime,1,
    MarketDataMode::Live,$sourceTime,null,str_repeat('b',64),
);
$tokenState=new MarketState(
    InstrumentId::fromString('instrument:aaplx'),VenueId::fromString('venue:bybit'),
    MarketSourceId::fromString('source:bybit'),null,new MarketQuote(
        new Price(Decimal::fromString('100.8'),$tok,$usdt,4),new Quantity(Decimal::fromString('20'),$tok,8),
        new Price(Decimal::fromString('100.9'),$tok,$usdt,4),new Quantity(Decimal::fromString('20'),$tok,8)
    ),null,null,
    MarketStatus::Open,$sourceTime,$sourceTime,$quality,1,null,str_repeat('c',64),MarketDataMode::Live,
);
$config=new SpreadDetectorConfig(
    'v1',Decimal::fromString('10'),Decimal::fromString('1'),Decimal::fromString('1'),
    1000,250,80,Decimal::fromString('20'),500,Decimal::fromString('0.8'),
);
$candidates=(new TokenizedEquitySpreadDetector())->detectReferenceDislocation(
    'pair:aapl-aaplx',$reference,$tokenState,$config,$now,1000,
    new ConversionRate($usdt,$usd,Decimal::fromString('0.999'),$sourceTime,MarketSourceId::fromString('source:fx'),MarketTrustStatus::Trusted),
);
$assert(count($candidates)===1,'H1 should detect token-over-reference dislocation.');
$assert($candidates[0]->hypothesis->value==='H1','H1 candidate hypothesis drifted.');
$assert(isset($candidates[0]->evidence['conversion']),'H1 cross-currency comparison must preserve conversion evidence.');

$economics=(new NetEconomicsEngine())->estimate(
    $candidates[0]->buyPrice,$candidates[0]->sellPrice,Decimal::fromString('10'),
    Decimal::fromString('0.001'),Decimal::fromString('0.001'),
    Decimal::fromString('0'),Decimal::fromString('0'),
);
$opportunity=new Opportunity(
    'opp:h1',$candidates[0],$economics,Decimal::fromString('1000'),$candidates[0]->capitalCapacity,
    Decimal::fromString('0.8'),30,OpportunityStatus::Valid,$now,
);
$risk=(new TokenizedEquityRiskEngine())->assess(
    $opportunity,Decimal::fromString('10'),Decimal::fromString('5000'),70,$now,false,false,
);
$assert(!$risk->approved(),'H1 must fail closed when hedge is unavailable.');
$assert(in_array('HEDGE_UNAVAILABLE',$risk->blockingReasons,true),'H1 risk rejection must explain missing hedge.');

$riskWithHedge=(new TokenizedEquityRiskEngine())->assess(
    $opportunity,Decimal::fromString('10'),Decimal::fromString('5000'),70,$now,true,false,
);
$assert($riskWithHedge->approved(),'H1 should be risk-approvable when a real executable hedge venue is available.');
$assert(!in_array('HEDGE_UNAVAILABLE',$riskWithHedge->blockingReasons,true),'H1 hedge blocker must clear when executable hedge exists.');

echo "Capital Markets Tokenized Equity H1/risk passed.\n";
