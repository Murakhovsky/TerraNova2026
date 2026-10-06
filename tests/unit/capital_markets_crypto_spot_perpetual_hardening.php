<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Execution\CompensationPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperFill;
use Domains\CapitalMarkets\Domain\MarketData\BasisObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateType;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\MarketQualityFlag;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketValueObservation;
use Domains\CapitalMarkets\Domain\MarketData\OrderBookLevel;
use Domains\CapitalMarkets\Domain\MarketData\SpotPerpetualMarketState;
use Domains\CapitalMarkets\Domain\Opportunity\ExpectedEconomics;
use Domains\CapitalMarkets\Domain\Opportunity\HypothesisCode;
use Domains\CapitalMarkets\Domain\Opportunity\Opportunity;
use Domains\CapitalMarkets\Domain\Opportunity\OpportunityStatus;
use Domains\CapitalMarkets\Domain\Opportunity\OpportunityType;
use Domains\CapitalMarkets\Domain\Opportunity\RelativeValueCandidate;
use Domains\CapitalMarkets\Domain\Performance\RelativeValuePerformance;
use Domains\CapitalMarkets\Domain\Portfolio\Position;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Service\BasisStatisticsEngine;
use Domains\CapitalMarkets\Domain\Service\ExecutablePriceCalculator;
use Domains\CapitalMarkets\Domain\Service\ExecutionCompensationEngine;
use Domains\CapitalMarkets\Domain\Service\FundingCashflowCalculator;
use Domains\CapitalMarkets\Domain\Service\FundingStatisticsEngine;
use Domains\CapitalMarkets\Domain\Service\PaperMultiLegExecutionSimulator;
use Domains\CapitalMarkets\Domain\Service\PositionProjector;
use Domains\CapitalMarkets\Domain\Service\RelativeValueEconomicsCalculator;
use Domains\CapitalMarkets\Domain\Service\RelativeValueExitEvaluator;
use Domains\CapitalMarkets\Domain\Service\RelativeValuePerformanceEngine;
use Domains\CapitalMarkets\Domain\Strategy\ExitDecision;
use Domains\CapitalMarkets\Domain\Strategy\RelativeValueExitPolicy;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MarketDataHydrator;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$now=new DateTimeImmutable('2026-10-06T18:00:00+00:00');
$base=new AssetCode('BTC');$quoteAsset=new AssetCode('USDT');
$quality=new MarketDataQualityAssessment(MarketTrustStatus::Trusted,95,[],10,5,15,null);
$quote=static fn(string $bid,string $ask)=>new MarketQuote(
    new Price(Decimal::fromString($bid),new AssetCode('BTC'),new AssetCode('USDT'),2),
    new Quantity(Decimal::fromString('10'),new AssetCode('BTC'),8),
    new Price(Decimal::fromString($ask),new AssetCode('BTC'),new AssetCode('USDT'),2),
    new Quantity(Decimal::fromString('10'),new AssetCode('BTC'),8)
);

$hydrator=new MarketDataHydrator();
$hydrated=$hydrator->marketState([
    'instrument_id'=>'instrument:btc-perp','venue_id'=>'venue:bybit','source_id'=>'source:bybit',
    'last_trade'=>null,'best_quote'=>$quote('100','100.2')->toArray(),'order_book'=>null,'volume'=>'123',
    'funding_rate'=>['value'=>'0.0005','unit'=>null,'attributes'=>[
        'status'=>'CURRENT','rate_type'=>'NORMALIZED_LONGS_PAY_SHORTS',
        'funding_interval_seconds'=>28800,'next_settlement_at'=>'1791338400000'
    ]],
    'open_interest'=>['value'=>'10000','unit'=>'BTC','attributes'=>[]],
    'mark_price'=>['value'=>'100.1','unit'=>'USDT','attributes'=>[]],
    'index_price'=>['value'=>'100','unit'=>'USDT','attributes'=>[]],
    'market_status'=>'OPEN','source_timestamp'=>$now->format(DATE_ATOM),'updated_at'=>$now->format(DATE_ATOM),
    'latency'=>['ingestion_ms'=>10,'processing_ms'=>5,'event_age_ms'=>15],
    'quality_status'=>'TRUSTED','quality_score'=>95,'quality_flags'=>[],'reference_deviation_bps'=>null,
    'state_version'=>4,'last_sequence'=>null,'last_event_fingerprint'=>str_repeat('a',64),'mode'=>'LIVE',
]);
$assert($hydrated->fundingRate?->value->value()==='0.0005','Funding rate must survive MarketState persistence hydration.');
$assert(($hydrated->fundingRate?->attributes['funding_interval_seconds']??null)===28800,'Funding interval attributes must survive persistence hydration.');
$assert($hydrated->markPrice?->value->value()==='100.1','Mark price must survive MarketState hydration.');
$assert($hydrated->indexPrice?->value->value()==='100','Index price must survive MarketState hydration.');
$assert($hydrated->openInterest?->value->value()==='10000','Open interest must survive MarketState hydration.');

$shortFills=[
    new PaperFill('s1','g','venue:bybit','BTC-PERP',ExecutionSide::Sell,Decimal::fromString('10'),Decimal::fromString('100'),Decimal::fromString('1'),Decimal::fromString('0'),$now,'s1'),
    new PaperFill('s2','g','venue:bybit','BTC-PERP',ExecutionSide::Buy,Decimal::fromString('4'),Decimal::fromString('90'),Decimal::fromString('0.4'),Decimal::fromString('0'),$now->modify('+1 hour'),'s2'),
];
$short=(new PositionProjector())->projectDirectional(
    'paper','FundingCaptureStrategy-v1','BTC-PERP','venue:bybit',$shortFills,Decimal::fromString('95'),
    PositionSide::Short,Decimal::fromString('1')
);
$assert($short->quantity->value()==='6','Short projector must retain six units after partial cover.');
$assert($short->realizedPnl->value()==='40','Short realized P&L direction is wrong.');
$assert($short->unrealizedPnl()->value()==='30','Short unrealized P&L direction is wrong.');

$bookA=new MarketOrderBook(MarketEventType::OrderBookSnapshot,'a',
    [new OrderBookLevel(new Price(Decimal::fromString('100'),$base,$quoteAsset,2),new Quantity(Decimal::fromString('5'),$base,8))],
    [new OrderBookLevel(new Price(Decimal::fromString('100.2'),$base,$quoteAsset,2),new Quantity(Decimal::fromString('5'),$base,8))]
);
$bookB=new MarketOrderBook(MarketEventType::OrderBookSnapshot,'b',
    [new OrderBookLevel(new Price(Decimal::fromString('99.8'),$base,$quoteAsset,2),new Quantity(Decimal::fromString('5'),$base,8))],
    [new OrderBookLevel(new Price(Decimal::fromString('100'),$base,$quoteAsset,2),new Quantity(Decimal::fromString('5'),$base,8))]
);
$simulation=(new PaperMultiLegExecutionSimulator(new ExecutablePriceCalculator(),new ExecutionCompensationEngine()))
    ->simulateTwoLeg($bookA,ExecutionSide::Sell,$bookB,ExecutionSide::Buy,Decimal::fromString('5'),CompensationPolicy::EmergencyClose);
$assert($simulation['first']['side']===ExecutionSide::Sell,'Generic simulator must preserve first-leg side.');
$assert($simulation['second']['side']===ExecutionSide::Buy,'Generic simulator must preserve second-leg side.');
$assert($simulation['residual_unhedged_quantity']->isZero(),'Fully liquid generic hedge must have zero residual delta.');

$spotState=new MarketState(
    InstrumentId::fromString('instrument:btc-spot'),VenueId::fromString('venue:bybit'),MarketSourceId::fromString('source:spot'),
    null,$quote('99.9','100'),null,null,MarketStatus::Open,$now,$now,$quality,1,null,str_repeat('b',64),MarketDataMode::Live
);
$perpState=new MarketState(
    InstrumentId::fromString('instrument:btc-perp'),VenueId::fromString('venue:bybit'),MarketSourceId::fromString('source:perp'),
    null,$quote('101','101.1'),null,null,MarketStatus::Open,$now,$now,$quality,1,null,str_repeat('c',64),MarketDataMode::Live
);
$funding=new FundingRateObservation(
    $perpState->venueId,$perpState->instrumentId,Decimal::fromString('0.0005'),
    FundingRateType::NormalizedLongsPayShorts,$now,$now->modify('+8 hours'),28800,
    null,null,'BYBIT',95,FundingRateStatus::Current
);
$basis=BasisObservation::fromTopOfBook(
    $spotState->key(),$perpState->key(),$now,
    Decimal::fromString('99.9'),Decimal::fromString('100'),Decimal::fromString('101'),Decimal::fromString('101.1')
);
$combined=new SpotPerpetualMarketState(
    $spotState,$perpState,$basis,$funding,Decimal::fromString('101.05'),Decimal::fromString('100'),
    Decimal::fromString('5000'),90,true,95,$now
);
$calc=new RelativeValueEconomicsCalculator(new FundingCashflowCalculator());
$h5=$calc->spotPerp(
    $combined,Decimal::fromString('1'),86400,Decimal::fromString('0.0001'),Decimal::fromString('0.0001'),
    Decimal::fromString('1'),Decimal::fromString('2'),Decimal::fromString('0'),Decimal::fromString('0.1'),
    Decimal::fromString('0'),Decimal::fromString('5'),false
);
$assert($h5->expectedBasisPnlAttribution->isZero(),'Funding-only H5 must not invent basis-convergence P&L.');
$assert($h5->expectedPriceNeutralizationPnl->isZero(),'Funding-only H5 price-neutralization P&L should be zero at entry model.');
$assert($calc->settlementCount($funding,$now,86400)===3,'Funding horizon must count actual future settlements from next settlement.');

$stats=(new FundingStatisticsEngine())->summarize([
    $funding,
    new FundingRateObservation($perpState->venueId,$perpState->instrumentId,Decimal::fromString('0.0004'),
        FundingRateType::NormalizedLongsPayShorts,$now->modify('+8 hours'),$now->modify('+16 hours'),28800,null,null,'BYBIT',95,FundingRateStatus::Settled),
    new FundingRateObservation($perpState->venueId,$perpState->instrumentId,Decimal::fromString('-0.0001'),
        FundingRateType::NormalizedLongsPayShorts,$now->modify('+16 hours'),$now->modify('+24 hours'),28800,null,null,'BYBIT',95,FundingRateStatus::Settled),
]);
$assert($stats['count']===3,'Funding statistics count drifted.');
$assert($stats['positive_rate_share']==='0.66666666','Funding positive share must be deterministic.');
$assert($stats['sign_change_frequency']==='0.5','Funding sign-change frequency must reflect transitions.');

$basisStats=(new BasisStatisticsEngine())->summarize([$basis]);
$assert($basisStats['count']===1,'Basis history statistics count drifted.');
$assert($basisStats['mean_mid_basis_bps']===$basis->midBasisBps->value(),'Single-observation basis mean must equal the observation.');

$exitPolicy=new RelativeValueExitPolicy(
    Decimal::fromString('10'),Decimal::fromString('100'),3600,Decimal::fromString('1')
);
$exit=(new RelativeValueExitEvaluator())->evaluate(
    $now,$now->modify('+2 hours'),Decimal::fromString('50'),Decimal::fromString('12'),Decimal::fromString('5'),$exitPolicy
);
$assert($exit->decision===ExitDecision::Exit,'Maximum holding period must trigger exit.');

$positionLong=new Position(
    'BTC','venue:spot',Decimal::fromString('1'),Decimal::fromString('100'),Decimal::fromString('105'),
    Decimal::fromString('1'),Decimal::fromString('0'),'pl','p','s',$now,$now,null,PositionSide::Long,null
);
$positionShort=new Position(
    'BTC-PERP','venue:perp',Decimal::fromString('1'),Decimal::fromString('101'),Decimal::fromString('105'),
    Decimal::fromString('1'),Decimal::fromString('0'),'ps','p','s',$now,$now,null,PositionSide::Short,Decimal::fromString('1')
);
$perf=(new RelativeValuePerformanceEngine())->calculate(
    [$positionLong,$positionShort],[Decimal::fromString('3')],
    Decimal::fromString('0'),Decimal::fromString('0'),Decimal::fromString('1')
);
$assert($perf->spotPricePnl->value()==='5','Spot price P&L attribution drifted.');
$assert($perf->derivativePricePnl->value()==='-4','Derivative price P&L attribution drifted.');
$assert($perf->netPnl->value()==='2','Performance must count price + funding - fees exactly once.');
$assert($perf->basisAttribution->value()==='1'&&$perf->basisIsAttributionOnly(),'Basis must remain non-additive attribution.');

$rvCandidate=new RelativeValueCandidate(
    'rv1',HypothesisCode::FundingRateCapture,OpportunityType::FundingCapture,'pair:btc',$now,$now->modify('+10 seconds'),
    [['venue_id'=>'v1'],['venue_id'=>'v1']],Decimal::fromString('100'),Decimal::fromString('100'),95,[]
);
$eco=new ExpectedEconomics(
    Decimal::fromString('0'),Decimal::fromString('5'),Decimal::fromString('0'),Decimal::fromString('1'),
    Decimal::fromString('0'),Decimal::fromString('0'),Decimal::fromString('0'),Decimal::fromString('0'),
    Decimal::fromString('100'),3600
);
$opportunity=new Opportunity(
    'opp-rv',$rvCandidate,null,Decimal::fromString('100'),Decimal::fromString('100'),Decimal::fromString('0.9'),
    10,OpportunityStatus::Approved,$now,[],OpportunityType::FundingCapture,$eco,'FundingCaptureStrategy-v1'
);
$assert($opportunity->expectedNetPnl()->value()==='4','Generic Opportunity must support ExpectedEconomics without SpreadCandidate economics.');
$assert($opportunity->executableAt($now),'Relative-value Opportunity should be executable before expiry with positive net P&L.');

echo "Capital Markets Crypto Spot/Perpetual hardening passed.\n";
