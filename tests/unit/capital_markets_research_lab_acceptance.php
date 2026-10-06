<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\BasisObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateType;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Research\StrategyPromotionGate;
use Domains\CapitalMarkets\Domain\Service\FundingCashflowCalculator;
use Domains\CapitalMarkets\Domain\Service\RelativeValueEconomicsCalculator;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$economics=new RelativeValueEconomicsCalculator(new FundingCashflowCalculator());
$at=new DateTimeImmutable('2026-01-01T10:00:00Z');

$basis=new BasisObservation(
    'SPOT-A','PERP-A',$at,
    Decimal::fromString('99.9'),Decimal::fromString('100'),
    Decimal::fromString('99.95'),
    Decimal::fromString('102'),Decimal::fromString('102.1'),
    Decimal::fromString('102.05'),
    Decimal::fromString('102'),Decimal::fromString('100'),
    Decimal::fromString('2.10'),Decimal::fromString('210.10505253'),
    Decimal::fromString('2'),Decimal::fromString('-2.2'),100,
);
$funding=new FundingRateObservation(
    VenueId::fromString('venue-a'),
    InstrumentId::fromString('perp-a'),
    Decimal::fromString('0.001'),
    FundingRateType::NormalizedLongsPayShorts,
    $at,
    $at->modify('+10 minutes'),
    3600,
    null,null,'fixture',100,FundingRateStatus::Current,
);

$h4=$economics->historicalSpotPerp(
    $basis,$funding,Decimal::fromString('1'),3600,
    Decimal::fromString('0.0001'),Decimal::fromString('0.0001'),
    Decimal::fromString('1'),
    Decimal::fromString('2'),
    Decimal::fromString('0'),
    Decimal::fromString('0.01'),
    Decimal::fromString('0'),
    Decimal::fromString('5'),
    true,
);
$assert($h4->expectedNetPnl->isPositive(),'H4 positive fixture must retain positive net edge after costs.');
$assert($h4->basisAttributionIsNonAdditive(),'H4 basis attribution must not be double counted.');

$fundingA=new FundingRateObservation(
    VenueId::fromString('venue-a'),InstrumentId::fromString('perp-a'),
    Decimal::fromString('0.00010'),FundingRateType::NormalizedLongsPayShorts,$at,$at->modify('+10 minutes'),
    3600,null,null,'fixture',100,FundingRateStatus::Current,
);
$fundingB=new FundingRateObservation(
    VenueId::fromString('venue-b'),InstrumentId::fromString('perp-b'),
    Decimal::fromString('0.00011'),FundingRateType::NormalizedLongsPayShorts,$at,$at->modify('+10 minutes'),
    3600,null,null,'fixture',100,FundingRateStatus::Current,
);
$h6=$economics->crossVenueFunding(
    $fundingA,$fundingB,
    Decimal::fromString('100'),Decimal::fromString('100'),
    Decimal::fromString('1'),3600,
    Decimal::fromString('0.002'),Decimal::fromString('0.002'),
    Decimal::fromString('10'),
    Decimal::fromString('2'),Decimal::fromString('2'),
    Decimal::fromString('0.01'),Decimal::fromString('0'),
    Decimal::fromString('5'),$at,
);
$assert($h6->expectedNetPnl->isNegative(),'H6 negative fixture must reject an edge destroyed by fees/slippage.');

$gate=new StrategyPromotionGate();
$passed=$gate->evaluate('OOS','PAPER',[
    'minimum_sample'=>250,
    'confidence'=>82,
    'max_drawdown_ratio'=>0.08,
],[
    'minimum_sample'=>['min'=>200],
    'confidence'=>['min'=>75],
    'max_drawdown_ratio'=>['max'=>0.10],
]);
$assert($passed['status']==='PASSED','OOS -> PAPER must pass when all deterministic criteria pass.');

$failed=$gate->evaluate('OOS','PAPER',[
    'minimum_sample'=>250,
    'confidence'=>70,
    'max_drawdown_ratio'=>0.08,
],[
    'minimum_sample'=>['min'=>200],
    'confidence'=>['min'=>75],
    'max_drawdown_ratio'=>['max'=>0.10],
]);
$assert($failed['status']==='FAILED','Composite score or partial success must not bypass a failed promotion criterion.');

$manual=$gate->evaluate('PAPER','LIMITED_LIVE',[
    'minimum_sample'=>500,
],[
    'minimum_sample'=>['min'=>500],
    'manual_review_required'=>true,
]);
$assert($manual['status']==='MANUAL_REVIEW_REQUIRED','PAPER -> LIMITED_LIVE must support explicit human gate.');

echo "Capital Markets Research Lab H4/H6 acceptance tests passed.\n";
