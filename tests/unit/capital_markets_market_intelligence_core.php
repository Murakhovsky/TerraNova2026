<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketClock;
use Domains\CapitalMarkets\Domain\MarketData\MarketComparability;
use Domains\CapitalMarkets\Domain\MarketData\MarketComparabilityStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityEngine;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\MarketQualityFlag;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketStateEngine;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrade;
use Domains\CapitalMarkets\Domain\MarketData\OrderBookLevel;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\MarketData\SequenceSemantics;
use Domains\CapitalMarkets\Domain\MarketData\TradeSide;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

final readonly class CmFixedMarketClock implements MarketClock
{
    public function __construct(private DateTimeImmutable $time,private bool $reliable=true){}
    public function now():DateTimeImmutable{return $this->time;}
    public function isReliable():bool{return $this->reliable;}
}

$base=new AssetCode('AAPLX');
$quote=new AssetCode('USDT');
$bid=new Price(Decimal::fromString('293.48'),$base,$quote,2);
$ask=new Price(Decimal::fromString('293.61'),$base,$quote,2);
$bidQty=new Quantity(Decimal::fromString('12.5'),$base,8);
$askQty=new Quantity(Decimal::fromString('10'),$base,8);
$marketQuote=new MarketQuote($bid,$bidQty,$ask,$askQty);

$assert($marketQuote->mid()->value()==='293.545','Quote mid must use deterministic decimal arithmetic.');
$assert($marketQuote->spreadAbsolute()->value()==='0.13','Quote spread absolute drifted.');
$assert(str_starts_with($marketQuote->spreadBps()->value(),'4.4286'),'Quote spread bps drifted.');
$assert(DecimalMath::add(Decimal::fromString('99999999999999999999.9'),Decimal::fromString('0.1'))->value()==='100000000000000000000','Decimal add lost precision.');
$assert(DecimalMath::divide(Decimal::fromString('1'),Decimal::fromString('8'),6)->value()==='0.125','Decimal divide lost precision.');

$source=MarketSourceId::fromString('source:bybit');
$venue=VenueId::fromString('venue:bybit');
$instrument=InstrumentId::fromString('instrument:aaplx');
$sourceAt=new DateTimeImmutable('2026-10-06T00:00:00.000000+00:00');
$receivedAt=new DateTimeImmutable('2026-10-06T00:00:00.040000+00:00');
$normalizedAt=new DateTimeImmutable('2026-10-06T00:00:00.050000+00:00');
$clock=new CmFixedMarketClock(new DateTimeImmutable('2026-10-06T00:00:00.064000+00:00'));
$policy=new MarketHealthPolicy(2000,5000,1000,5000,1000,500);

$quoteEvent=new CanonicalMarketEvent(
    'evt-q1',MarketEventType::Bbo,$source,$venue,$instrument,
    $sourceAt,$receivedAt,$normalizedAt,'10',SequenceSemantics::Monotonic,[],1,
    MarketDataMode::Live,MarketStatus::Open,$marketQuote
);
$quality=(new MarketDataQualityEngine())->assess($quoteEvent,$policy,MarketConnectionState::Active,$clock);
$assert($quality->trustStatus->value==='TRUSTED','Fresh active quote must be trusted.');
$assert($quality->eventAgeMs===64,'Event age calculation drifted.');
$assert($quality->ingestionLatencyMs===40,'Ingestion latency calculation drifted.');
$assert($quality->processingLatencyMs===10,'Processing latency calculation drifted.');

$stateEngine=new MarketStateEngine(new MarketDataQualityEngine());
$t1=$stateEngine->apply(null,$quoteEvent,$policy,MarketConnectionState::Active,$clock);
$assert($t1->eventApplied&&$t1->state->stateVersion===1,'Initial quote must create state.');

$trade2=new MarketTrade(
    'trade-2',
    new Price(Decimal::fromString('293.55'),$base,$quote,2),
    new Quantity(Decimal::fromString('1.25'),$base,8),
    TradeSide::Buy
);
$tradeEvent2=new CanonicalMarketEvent(
    'evt-t2',MarketEventType::Trade,$source,$venue,$instrument,
    new DateTimeImmutable('2026-10-06T00:00:00.060000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.061000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.062000+00:00'),
    '20',SequenceSemantics::Monotonic,[],1,MarketDataMode::Live,MarketStatus::Open,$trade2
);
$t2=$stateEngine->apply($t1->state,$tradeEvent2,$policy,MarketConnectionState::Active,$clock);
$assert(($t2->state->lastTrade?->tradeId)==='trade-2','New trade must update current state.');

$oldTrade=new MarketTrade(
    'trade-1',
    new Price(Decimal::fromString('293.40'),$base,$quote,2),
    new Quantity(Decimal::fromString('2'),$base,8),
    TradeSide::Sell
);
$oldTradeEvent=new CanonicalMarketEvent(
    'evt-t1',MarketEventType::Trade,$source,$venue,$instrument,
    new DateTimeImmutable('2026-10-06T00:00:00.055000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.063000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.064000+00:00'),
    '19',SequenceSemantics::Monotonic,[],1,MarketDataMode::Live,MarketStatus::Open,$oldTrade
);
$older=$stateEngine->apply($t2->state,$oldTradeEvent,$policy,MarketConnectionState::Active,$clock);
$assert(!$older->eventApplied&&!$older->stateChanged,'Out-of-order event must not mutate current state.');
$assert(in_array(MarketQualityFlag::OutOfOrder,$older->flags,true),'Out-of-order flag missing.');
$assert(($older->state->lastTrade?->tradeId)==='trade-2','Out-of-order event regressed current trade.');

$duplicate=$stateEngine->apply($t2->state,$tradeEvent2,$policy,MarketConnectionState::Active,$clock);
$assert(!$duplicate->eventApplied&&!$duplicate->stateChanged,'Duplicate event must be idempotent.');
$assert(in_array(MarketQualityFlag::Duplicate,$duplicate->flags,true),'Duplicate flag missing.');

$levelBid=new OrderBookLevel(
    new Price(Decimal::fromString('293.48'),$base,$quote,2),
    new Quantity(Decimal::fromString('5'),$base,8)
);
$levelAsk=new OrderBookLevel(
    new Price(Decimal::fromString('293.61'),$base,$quote,2),
    new Quantity(Decimal::fromString('4'),$base,8)
);
$book100=new MarketOrderBook(true,[$levelBid],[$levelAsk],'100');
$bookSnapshotEvent=new CanonicalMarketEvent(
    'evt-b100',MarketEventType::OrderBookSnapshot,$source,$venue,$instrument,
    new DateTimeImmutable('2026-10-06T00:00:00.070000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.071000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.072000+00:00'),
    '100',SequenceSemantics::Contiguous,[],1,MarketDataMode::Live,MarketStatus::Open,$book100
);
$b100=$stateEngine->apply($t2->state,$bookSnapshotEvent,$policy,MarketConnectionState::Active,new CmFixedMarketClock(new DateTimeImmutable('2026-10-06T00:00:00.080000+00:00')));
$assert($b100->state->orderBook!==null,'Order-book snapshot must initialize book.');

$delta101=new MarketOrderBook(false,[$levelBid],[],'101');
$deltaEvent101=new CanonicalMarketEvent(
    'evt-b101',MarketEventType::OrderBookDelta,$source,$venue,$instrument,
    new DateTimeImmutable('2026-10-06T00:00:00.081000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.082000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.083000+00:00'),
    '101',SequenceSemantics::Contiguous,[],1,MarketDataMode::Live,MarketStatus::Open,$delta101
);
$b101=$stateEngine->apply($b100->state,$deltaEvent101,$policy,MarketConnectionState::Active,new CmFixedMarketClock(new DateTimeImmutable('2026-10-06T00:00:00.090000+00:00')));
$assert($b101->eventApplied&&$b101->state->orderBook?->sequence==='101','Contiguous order-book delta must apply.');

$delta105=new MarketOrderBook(false,[$levelBid],[],'105');
$deltaEvent105=new CanonicalMarketEvent(
    'evt-b105',MarketEventType::OrderBookDelta,$source,$venue,$instrument,
    new DateTimeImmutable('2026-10-06T00:00:00.091000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.092000+00:00'),
    new DateTimeImmutable('2026-10-06T00:00:00.093000+00:00'),
    '105',SequenceSemantics::Contiguous,[],1,MarketDataMode::Live,MarketStatus::Open,$delta105
);
$gap=$stateEngine->apply($b101->state,$deltaEvent105,$policy,MarketConnectionState::Active,new CmFixedMarketClock(new DateTimeImmutable('2026-10-06T00:00:00.100000+00:00')));
$assert(!$gap->eventApplied&&$gap->stateChanged,'Sequence gap must change trust state without applying corrupt delta.');
$assert($gap->state->orderBook===null,'Sequence gap must invalidate order book.');
$assert($gap->state->quality->trustStatus->value==='UNTRUSTED','Sequence gap must remove trust.');

$referenceBase=new AssetCode('AAPL');
$usd=new AssetCode('USD');
$referenceQuote=new MarketQuote(
    new Price(Decimal::fromString('293.32'),$referenceBase,$usd,2),new Quantity(Decimal::fromString('7'),$referenceBase,2),
    new Price(Decimal::fromString('293.36'),$referenceBase,$usd,2),new Quantity(Decimal::fromString('4'),$referenceBase,2)
);
$referenceEvent=new CanonicalMarketEvent(
    'evt-ref',MarketEventType::ReferencePrice,MarketSourceId::fromString('source:massive'),null,InstrumentId::fromString('instrument:aapl'),
    $sourceAt,$receivedAt,$normalizedAt,'55',SequenceSemantics::Monotonic,[],1,MarketDataMode::Live,MarketStatus::Open,$referenceQuote
);
$referenceQuality=(new MarketDataQualityEngine())->assess($referenceEvent,$policy,MarketConnectionState::Active,$clock);
$referenceState=new ReferenceMarketState(
    MarketSourceId::fromString('source:massive'),InstrumentId::fromString('instrument:aapl'),$referenceQuote,MarketStatus::Open,
    'REGULAR',$referenceQuote,null,'NBBO',MarketDataMode::Live,$sourceAt,$normalizedAt,$referenceQuality,1
);
$comparability=(new MarketComparability())->compare($t1->state,$referenceState,$quote,$usd,null);
$assert($comparability===MarketComparabilityStatus::CrossCurrency,'USDT/USD markets must not be directly comparable without conversion.');

echo "Capital Markets Market Intelligence core passed.\n";
