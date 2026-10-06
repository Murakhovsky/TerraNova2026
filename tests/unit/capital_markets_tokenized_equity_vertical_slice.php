<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperFill;
use Domains\CapitalMarkets\Domain\Ledger\LedgerEntry;
use Domains\CapitalMarkets\Domain\Ledger\LedgerTransaction;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\MarketData\OrderBookLevel;
use Domains\CapitalMarkets\Domain\Opportunity\HypothesisCode;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDetectorConfig;
use Domains\CapitalMarkets\Domain\Service\ExecutablePriceCalculator;
use Domains\CapitalMarkets\Domain\Service\NetEconomicsEngine;
use Domains\CapitalMarkets\Domain\Service\PaperPnlEngine;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquitySpreadDetector;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$base=new AssetCode('AAPLX');
$quote=new AssetCode('USD');
$book=new MarketOrderBook(MarketEventType::OrderBookSnapshot,'1',
    [new OrderBookLevel(new Price(Decimal::fromString('100.8'),$base,$quote,4),new Quantity(Decimal::fromString('4'),$base,8))],
    [
        new OrderBookLevel(new Price(Decimal::fromString('100'),$base,$quote,4),new Quantity(Decimal::fromString('2'),$base,8)),
        new OrderBookLevel(new Price(Decimal::fromString('100.2'),$base,$quote,4),new Quantity(Decimal::fromString('3'),$base,8)),
    ],
);
$calculator=new ExecutablePriceCalculator();
$vwap=$calculator->vwap($book,ExecutionSide::Buy,Decimal::fromString('4'));
$assert($vwap['price']->value()==='100.1','VWAP must consume multiple levels exactly.');
$partial=$calculator->executableFill($book,ExecutionSide::Buy,Decimal::fromString('7'));
$assert($partial['filled_quantity']->value()==='5','Partial fill must consume all available acceptable depth.');
$assert($partial['remaining_quantity']->value()==='2','Partial fill must preserve remaining quantity.');
$assert($partial['fully_filled']===false,'Insufficient depth must produce partial fill, not pretend completion.');

$eco=(new NetEconomicsEngine())->estimate(
    Decimal::fromString('100'),Decimal::fromString('100.8'),Decimal::fromString('25'),
    Decimal::fromString('0.001'),Decimal::fromString('0.001'),
    Decimal::fromString('1.25'),Decimal::fromString('1.25'),
);
$assert($eco->grossPnl->value()==='20','H2 gross PnL golden value drifted.');
$assert($eco->totalCost->value()==='7.52','H2 total cost golden value drifted.');
$assert($eco->expectedNetPnl->value()==='12.48','H2 expected net PnL golden value drifted.');

$now=new DateTimeImmutable('2026-10-06T12:00:00.500000+00:00');
$q=static fn(string $bid,string $ask)=>new MarketQuote(
    new Price(Decimal::fromString($bid),new AssetCode('AAPLX'),new AssetCode('USD'),4),
    new Quantity(Decimal::fromString('10'),new AssetCode('AAPLX'),8),
    new Price(Decimal::fromString($ask),new AssetCode('AAPLX'),new AssetCode('USD'),4),
    new Quantity(Decimal::fromString('10'),new AssetCode('AAPLX'),8),
);
$quality=new MarketDataQualityAssessment(MarketTrustStatus::Trusted,100,[],100,10,110,null);
$state=static fn(string $venue,string $instrument,string $bid,string $ask,string $ts)=>new MarketState(
    InstrumentId::fromString($instrument),
    VenueId::fromString($venue),
    MarketSourceId::fromString('source:'.$venue),
    null,$q($bid,$ask),null,null,MarketStatus::Open,
    new DateTimeImmutable($ts),new DateTimeImmutable($ts),$quality,1,null,str_repeat('a',64),MarketDataMode::Live,
);
$a=$state('venue:a','instrument:aaplx-a','100','100.1','2026-10-06T12:00:00.400000+00:00');
$b=$state('venue:b','instrument:aaplx-b','100.8','100.9','2026-10-06T12:00:00.410000+00:00');
$config=new SpreadDetectorConfig(
    'v1',Decimal::fromString('10'),Decimal::fromString('1'),Decimal::fromString('1'),
    1000,250,80,Decimal::fromString('20'),500,Decimal::fromString('0.8'),
);
$candidates=(new TokenizedEquitySpreadDetector())->detect(
    HypothesisCode::CrossVenueTokenizedEquityArbitrage,'pair:aaplx',$a,$b,$config,$now,1000,
);
$assert(count($candidates)===1,'Cross-venue detector should produce exactly one direction.');
$assert($candidates[0]->grossSpread->value()==='0.7','Detector must compare buy ask to sell bid.');

$buy=new PaperFill('f1','g1','venue:a','aaplx',ExecutionSide::Buy,Decimal::fromString('10'),Decimal::fromString('100.1'),Decimal::fromString('1'),Decimal::fromString('0.5'),$now,'idem:f1');
$sell=new PaperFill('f2','g1','venue:b','aaplx',ExecutionSide::Sell,Decimal::fromString('10'),Decimal::fromString('100.8'),Decimal::fromString('1'),Decimal::fromString('0.5'),$now,'idem:f2');
$pnl=(new PaperPnlEngine())->realized([$buy,$sell]);
$assert($pnl->value()==='5','Paper net P&L golden value drifted.');

$tx=new LedgerTransaction('tx1','idem:tx1',$now,[
    new LedgerEntry('cash',Decimal::fromString('10'),Decimal::fromString('0')),
    new LedgerEntry('pnl',Decimal::fromString('0'),Decimal::fromString('10')),
]);
$assert(count($tx->entries)===2,'Balanced ledger transaction rejected.');

$failed=false;
try{
    new LedgerTransaction('bad','idem:bad',$now,[
        new LedgerEntry('cash',Decimal::fromString('10'),Decimal::fromString('0')),
        new LedgerEntry('pnl',Decimal::fromString('0'),Decimal::fromString('9')),
    ]);
}catch(DomainException){$failed=true;}
$assert($failed,'Ledger imbalance must fail closed.');

echo "Capital Markets Tokenized Equity vertical slice core passed.\n";
