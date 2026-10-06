<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\BackpressureDecision;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\ConversionRate;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketQualityFlag;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTimestamps;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrade;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\MarketData\OrderBookLevel;
use Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use Domains\CapitalMarkets\Domain\MarketData\TradeSide;
use Domains\CapitalMarkets\Domain\Service\MarketBackpressurePolicy;
use Domains\CapitalMarkets\Domain\Service\MarketDataQualityEngine;
use Domains\CapitalMarkets\Domain\Service\MarketStateEngine;
use Domains\CapitalMarkets\Domain\Service\OrderBookRebuilder;
use Domains\CapitalMarkets\Domain\Service\ReferenceMarketStateEngine;
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

$assert(count(MarketSourceRole::cases())===5,'Market source role vocabulary drifted.');
$assert(count(MarketDataCapability::cases())===11,'Market-data capability vocabulary drifted.');
$assert(count(MarketEventType::cases())===12,'Canonical market event vocabulary drifted.');
$assert(count(MarketConnectionState::cases())===9,'Connection-state vocabulary drifted.');

$assert(DecimalMath::add(Decimal::fromString('99999999999999999999.9'),Decimal::fromString('0.1'))->value()==='100000000000000000000','Decimal add lost precision.');
$assert(DecimalMath::subtract(Decimal::fromString('100.01'),Decimal::fromString('0.02'))->value()==='99.99','Decimal subtract failed.');
$assert(DecimalMath::multiply(Decimal::fromString('12.5'),Decimal::fromString('0.08'))->value()==='1','Decimal multiply failed.');
$assert(DecimalMath::divide(Decimal::fromString('1'),Decimal::fromString('8'),6)->value()==='0.125','Decimal divide failed.');
$assert(DecimalMath::midpoint(Decimal::fromString('100'),Decimal::fromString('102'),4)->value()==='101','Decimal midpoint failed.');

$base=new AssetCode('AAPLX');
$quote=new AssetCode('USDT');
$bid=new Price(Decimal::fromString('100'),$base,$quote,4);
$ask=new Price(Decimal::fromString('102'),$base,$quote,4);
$bidQty=new Quantity(Decimal::fromString('2'),$base,8);
$askQty=new Quantity(Decimal::fromString('3'),$base,8);
$bbo=new MarketQuote($bid,$bidQty,$ask,$askQty);
$assert($bbo->midPrice()->value()==='101','BBO mid price failed.');
$assert($bbo->spreadAbsolute()->value()==='2','BBO spread failed.');
$assert($bbo->spreadBps()->value()==='198.019801','BBO spread bps failed.');

$crossed=new MarketQuote(
    new Price(Decimal::fromString('103'),$base,$quote,4),$bidQty,
    new Price(Decimal::fromString('102'),$base,$quote,4),$askQty,
);
$assert($crossed->isCrossed(),'Crossed BBO was not detected.');

$reconnect=new ReconnectPolicy(1000,8000,0);
$assert($reconnect->delayForAttempt(1)===1000,'Reconnect attempt 1 delay drifted.');
$assert($reconnect->delayForAttempt(2)===2000,'Reconnect attempt 2 delay drifted.');
$assert($reconnect->delayForAttempt(5)===8000,'Reconnect delay must cap at configured maximum.');

$source=MarketSourceId::fromString('bybit-xstocks-spot');
$venue=VenueId::fromString('venue:bybit');
$instrument=InstrumentId::fromString('instrument:aaplx');
$t0=new DateTimeImmutable('2026-10-06T00:00:00.000000+00:00');
$received=new DateTimeImmutable('2026-10-06T00:00:00.100000+00:00');
$processed=new DateTimeImmutable('2026-10-06T00:00:00.120000+00:00');
$event=new CanonicalMarketEvent(
    'evt-1',$source,$venue,$instrument,new MarketTimestamps($t0,$received,$processed),'10',$bbo,[],1
);
$health=new MarketSourceHealth($source,MarketConnectionState::Active,$received,$received,0,0,true);
$policy=new MarketDataQualityPolicy([
    MarketEventType::Bbo->value=>2000,
    MarketEventType::Quote->value=>2000,
    MarketEventType::Trade->value=>5000,
    MarketEventType::OrderBookSnapshot->value=>2000,
    MarketEventType::OrderBookDelta->value=>2000,
    MarketEventType::Candle->value=>60000,
    MarketEventType::Volume->value=>60000,
    MarketEventType::ReferencePrice->value=>5000,
    MarketEventType::FundingRate->value=>60000,
    MarketEventType::OpenInterest->value=>60000,
    MarketEventType::MarkPrice->value=>5000,
    MarketEventType::IndexPrice->value=>5000,
],500,1000,500,1000,500,false);

$quality=(new MarketDataQualityEngine())->assess(
    $event,null,$health,new DateTimeImmutable('2026-10-06T00:00:00.200000+00:00'),$policy
);
$assert($quality->status===MarketTrustStatus::Trusted,'Fresh healthy BBO must be TRUSTED.');
$assert($quality->ingestionLatencyMilliseconds===100,'Ingestion latency calculation drifted.');
$assert($quality->processingLatencyMilliseconds===20,'Processing latency calculation drifted.');
$assert($quality->eventAgeMilliseconds===200,'Event age calculation drifted.');

$crossedEvent=new CanonicalMarketEvent(
    'evt-crossed',$source,$venue,$instrument,new MarketTimestamps($t0,$received,$processed),'11',$crossed,[],1
);
$crossedQuality=(new MarketDataQualityEngine())->assess(
    $crossedEvent,null,$health,new DateTimeImmutable('2026-10-06T00:00:00.200000+00:00'),$policy
);
$assert($crossedQuality->status===MarketTrustStatus::Untrusted,'Crossed market must be UNTRUSTED.');
$assert(in_array(MarketQualityFlag::CrossedMarket,$crossedQuality->flags,true),'Crossed market flag is missing.');

$staleQuality=(new MarketDataQualityEngine())->assess(
    $event,null,$health,new DateTimeImmutable('2026-10-06T00:00:10.000000+00:00'),$policy
);
$assert($staleQuality->status===MarketTrustStatus::Stale,'Old BBO must be STALE.');

$bookSnapshot=new MarketOrderBook(MarketEventType::OrderBookSnapshot,'100',[
    new OrderBookLevel(new Price(Decimal::fromString('100'),$base,$quote,4),new Quantity(Decimal::fromString('5'),$base,8)),
    new OrderBookLevel(new Price(Decimal::fromString('99'),$base,$quote,4),new Quantity(Decimal::fromString('4'),$base,8)),
],[
    new OrderBookLevel(new Price(Decimal::fromString('102'),$base,$quote,4),new Quantity(Decimal::fromString('6'),$base,8)),
]);
$bookDelta=new MarketOrderBook(MarketEventType::OrderBookDelta,'101',[
    new OrderBookLevel(new Price(Decimal::fromString('100'),$base,$quote,4),new Quantity(Decimal::fromString('0'),$base,8)),
    new OrderBookLevel(new Price(Decimal::fromString('100.5'),$base,$quote,4),new Quantity(Decimal::fromString('7'),$base,8)),
],[
    new OrderBookLevel(new Price(Decimal::fromString('102'),$base,$quote,4),new Quantity(Decimal::fromString('8'),$base,8)),
]);
$rebuilt=(new OrderBookRebuilder())->apply($bookSnapshot,$bookDelta);
$assert(count($rebuilt->bids)===2,'Order-book delete/insert delta failed.');
$assert($rebuilt->bids[0]->price->value->value()==='100.5','Rebuilt bids are not sorted descending.');
$assert($rebuilt->asks[0]->quantity->value->value()==='8','Order-book update failed.');

$stateEngine=new MarketStateEngine(new OrderBookRebuilder());
$state=$stateEngine->apply($event,$quality,null,MarketStatus::Open);
$assert($state->stateVersion===1,'Initial MarketState version must be 1.');
$assert($state->bestQuote?->midPrice()->value()==='101','MarketState lost BBO.');

$trade=new MarketTrade(
    'trade-1',
    new Price(Decimal::fromString('101.5'),$base,$quote,4),
    new Quantity(Decimal::fromString('0.25'),$base,8),
    TradeSide::Buy,
);
$tradeEvent=new CanonicalMarketEvent(
    'evt-trade',$source,$venue,$instrument,
    new MarketTimestamps(new DateTimeImmutable('2026-10-06T00:00:00.300000+00:00'),new DateTimeImmutable('2026-10-06T00:00:00.350000+00:00'),new DateTimeImmutable('2026-10-06T00:00:00.360000+00:00')),
    '12',$trade,[],1
);
$tradeQuality=(new MarketDataQualityEngine())->assess(
    $tradeEvent,$state,$health,new DateTimeImmutable('2026-10-06T00:00:00.400000+00:00'),$policy
);
$state2=$stateEngine->apply($tradeEvent,$tradeQuality,$state,MarketStatus::Open);
$assert($state2->stateVersion===2,'MarketState version did not increment.');
$assert($state2->lastTrade?->tradeId==='trade-1','MarketState lost last trade.');
$assert($state2->bestQuote?->midPrice()->value()==='101','Trade must not overwrite BBO.');

$duplicateQuality=(new MarketDataQualityEngine())->assess(
    $tradeEvent,$state2,$health,new DateTimeImmutable('2026-10-06T00:00:00.450000+00:00'),$policy
);
$assert(in_array(MarketQualityFlag::Duplicate,$duplicateQuality->flags,true),'Duplicate canonical event was not detected.');
$assert($stateEngine->apply($tradeEvent,$duplicateQuality,$state2,MarketStatus::Open)===$state2,'Duplicate event must not mutate current state.');

$referenceEngine=new ReferenceMarketStateEngine();
$referenceState=$referenceEngine->apply(
    $event,$quality,null,MarketSession::Regular,ReferenceType::Nbbo,new DateTimeImmutable('2026-10-06T00:00:00.200000+00:00')
);
$assert($referenceState->lastRegularMarketQuote!==null,'Regular-session reference quote was not preserved.');
$assert($referenceState->referenceAgeMilliseconds===200,'Reference age drifted.');

$backpressure=new MarketBackpressurePolicy(100);
$assert($backpressure->decide(MarketEventType::Bbo,101)===BackpressureDecision::Coalesce,'BBO should coalesce under backpressure.');
$assert($backpressure->decide(MarketEventType::Trade,101)===BackpressureDecision::Preserve,'Trades must be preserved under backpressure.');
$assert($backpressure->decide(MarketEventType::OrderBookDelta,101)===BackpressureDecision::Reject,'Order-book delta must reject when continuity is unsafe.');

$conversion=new ConversionRate(
    new AssetCode('USDT'),new AssetCode('USD'),Decimal::fromString('0.9998'),
    $t0,MarketSourceId::fromString('source:fx'),MarketTrustStatus::Trusted
);
$assert($conversion->usable(),'Trusted conversion rate must be usable.');

echo "Capital Markets Market Intelligence core passed.\n";
