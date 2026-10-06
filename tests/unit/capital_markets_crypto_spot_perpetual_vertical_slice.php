<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Execution\ExecutionGroup;
use Domains\CapitalMarkets\Domain\Execution\ExecutionGroupState;
use Domains\CapitalMarkets\Domain\Execution\ExecutionLeg;
use Domains\CapitalMarkets\Domain\Execution\ExecutionPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\HedgePolicy;
use Domains\CapitalMarkets\Domain\Instrument\SpotProfile;
use Domains\CapitalMarkets\Domain\MarketData\BasisObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateType;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\MarketData\SpotPerpetualMarketState;
use Domains\CapitalMarkets\Domain\Opportunity\ExpectedEconomics;
use Domains\CapitalMarkets\Domain\Opportunity\HypothesisCode;
use Domains\CapitalMarkets\Domain\Opportunity\OpportunityType;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeGroup;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeLeg;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeState;
use Domains\CapitalMarkets\Domain\Portfolio\Position;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Research\ResearchResultStatus;
use Domains\CapitalMarkets\Domain\Risk\DerivativesRiskPolicy;
use Domains\CapitalMarkets\Domain\Risk\LiquidationRiskState;
use Domains\CapitalMarkets\Domain\Risk\LiquidationRiskStatus;
use Domains\CapitalMarkets\Domain\Service\FundingCashflowCalculator;
use Domains\CapitalMarkets\Domain\Service\FundingRateNormalizer;
use Domains\CapitalMarkets\Domain\Service\FundingSettlementEligibility;
use Domains\CapitalMarkets\Domain\Service\HedgeMonitor;
use Domains\CapitalMarkets\Domain\Service\RelativeValueOpportunityEvaluator;
use Domains\CapitalMarkets\Domain\Service\RelativeValueRiskEvaluator;
use Domains\CapitalMarkets\Domain\Strategy\CrossVenueFundingStrategy;
use Domains\CapitalMarkets\Domain\Strategy\FundingCaptureStrategy;
use Domains\CapitalMarkets\Domain\Strategy\SpotPerpBasisStrategy;
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

$assert(HypothesisCode::SpotPerpetualBasis->value==='H4','H4 taxonomy missing.');
$assert(HypothesisCode::FundingRateCapture->value==='H5','H5 taxonomy missing.');
$assert(HypothesisCode::CrossVenueFunding->value==='H6','H6 taxonomy missing.');
$assert(OpportunityType::SpotPerpBasis->value==='SPOT_PERP_BASIS','Opportunity taxonomy drifted.');

$now=new DateTimeImmutable('2026-10-06T18:00:00+00:00');
$btc=new AssetCode('BTC');$usdt=new AssetCode('USDT');
$quote=static fn(string $bid,string $ask)=>new MarketQuote(
    new Price(Decimal::fromString($bid),new AssetCode('BTC'),new AssetCode('USDT'),2),
    new Quantity(Decimal::fromString('10'),new AssetCode('BTC'),8),
    new Price(Decimal::fromString($ask),new AssetCode('BTC'),new AssetCode('USDT'),2),
    new Quantity(Decimal::fromString('10'),new AssetCode('BTC'),8),
);
$quality=new MarketDataQualityAssessment(MarketTrustStatus::Trusted,100,[],1,1,2,null);
$state=static fn(string $venue,string $instrument,string $bid,string $ask)=>new MarketState(
    InstrumentId::fromString($instrument),VenueId::fromString($venue),MarketSourceId::fromString('source:'.$venue),
    null,$quote($bid,$ask),null,null,MarketStatus::Open,
    new DateTimeImmutable('2026-10-06T17:59:59.900000+00:00'),new DateTimeImmutable('2026-10-06T18:00:00+00:00'),
    $quality,1,null,str_repeat('a',64),MarketDataMode::Live,
);
$spotState=$state('venue:bybit','instrument:btc-spot','99.9','100');
$perpState=$state('venue:bybit','instrument:btc-perp','101','101.1');

$basis=BasisObservation::fromTopOfBook(
    'BYBIT:BTCUSDT:SPOT','BYBIT:BTCUSDT:PERP',$now,
    Decimal::fromString('99.9'),Decimal::fromString('100'),
    Decimal::fromString('101'),Decimal::fromString('101.1'),
    Decimal::fromString('101.02'),Decimal::fromString('100.02'),100
);
$assert($basis->longSpotShortPerpExecutableBasis->value()==='1','Premium executable basis must use spot ask and perp bid.');
$assert($basis->shortSpotLongPerpExecutableBasis->value()==='-1.2','Reverse executable basis must use spot bid and perp ask.');

$funding=new FundingRateObservation(
    VenueId::fromString('venue:bybit'),InstrumentId::fromString('instrument:btc-perp'),
    Decimal::fromString('0.0005'),FundingRateType::NormalizedLongsPayShorts,$now,
    $now->modify('+8 hours'),28800,Decimal::fromString('0.003'),Decimal::fromString('-0.003'),
    'BYBIT',100,FundingRateStatus::Current,
);
$market=new SpotPerpetualMarketState(
    $spotState,$perpState,$basis,$funding,Decimal::fromString('101.02'),Decimal::fromString('100.02'),
    Decimal::fromString('25000'),100,true,100,$now
);
$assert($market->trusted(),'Trusted spot/perpetual state unexpectedly rejected.');

$fundCalc=new FundingCashflowCalculator();
$assert($fundCalc->calculate($funding,Decimal::fromString('10000'),PositionSide::Short)->value()==='5','Positive funding must be received by short under normalized semantics.');
$assert($fundCalc->calculate($funding,Decimal::fromString('10000'),PositionSide::Long)->value()==='-5','Positive funding must be paid by long under normalized semantics.');

$normal=(new FundingRateNormalizer())->normalize($funding);
$assert($normal['daily_equivalent']==='0.0015','Funding daily equivalent must respect native interval.');
$assert($normal['assumption']==='OBSERVED_RATE_PERSISTS_UNCHANGED_FOR_DISPLAY_ONLY','Funding annualization warning contract drifted.');

$economics=new ExpectedEconomics(
    Decimal::fromString('10'),Decimal::fromString('5'),Decimal::fromString('10'),
    Decimal::fromString('2'),Decimal::fromString('1'),Decimal::fromString('0'),
    Decimal::fromString('0'),Decimal::fromString('1'),Decimal::fromString('10000'),86400,
);
$assert($economics->expectedNetPnl->value()==='11','Expected economics must not double count basis attribution.');
$assert($economics->basisAttributionIsNonAdditive(),'Basis attribution contract must be explicit.');

$spotProfile=new SpotProfile($btc,$usdt,Decimal::fromString('0.0001'),Decimal::fromString('5'),2,8,false,false,false);
$evaluator=new RelativeValueOpportunityEvaluator();
$h4=$evaluator->basis($market,$spotProfile,$economics,Decimal::fromString('0.5'));
$assert($h4->status===ResearchResultStatus::Validated,'Executable positive H4 should validate.');
$reverse=$evaluator->basis($market,$spotProfile,$economics,Decimal::fromString('-2'),true);
$assert($reverse->status===ResearchResultStatus::NotExecutable,'Reverse basis without spot short/borrow must be NOT_EXECUTABLE.');
$h5=$evaluator->fundingCapture($market,$economics);
$assert($h5->status===ResearchResultStatus::Validated,'Current trusted positive H5 should validate.');

$otherFunding=new FundingRateObservation(
    VenueId::fromString('venue:okx'),InstrumentId::fromString('instrument:btc-perp-okx'),
    Decimal::fromString('-0.0001'),FundingRateType::NormalizedLongsPayShorts,$now,
    $now->modify('+4 hours'),14400,Decimal::fromString('0.003'),Decimal::fromString('-0.003'),
    'OKX',100,FundingRateStatus::Current,
);
$h6=$evaluator->crossVenueFunding($otherFunding,$funding,$economics);
$assert($h6->status===ResearchResultStatus::Validated,'Positive cross-venue funding economics should validate.');

$hedge=new HedgeGroup('HG-1008','FundingCaptureStrategy-v1',[
    new HedgeLeg('instrument:btc-spot','venue:bybit',PositionSide::Long,Decimal::fromString('0.1'),Decimal::fromString('1'),Decimal::fromString('1')),
    new HedgeLeg('instrument:btc-perp','venue:bybit',PositionSide::Short,Decimal::fromString('100'),Decimal::fromString('1'),Decimal::fromString('0.001')),
],Decimal::fromString('0'),Decimal::fromString('0.001'),HedgeState::Hedged);
$assert($hedge->netUnderlyingExposure()->isZero(),'Contract multiplier must neutralize spot/perp exposure.');
$monitor=(new HedgeMonitor())->inspect($hedge);
$assert($monitor['state']===HedgeState::Hedged&&!$monitor['rehedge_required'],'Neutral hedge should stay HEDGED.');

$drifted=new HedgeGroup('HG-1009','FundingCaptureStrategy-v1',[
    new HedgeLeg('instrument:btc-spot','venue:bybit',PositionSide::Long,Decimal::fromString('0.1'),Decimal::fromString('1'),Decimal::fromString('1')),
    new HedgeLeg('instrument:btc-perp','venue:bybit',PositionSide::Short,Decimal::fromString('90'),Decimal::fromString('1'),Decimal::fromString('0.001')),
],Decimal::fromString('0'),Decimal::fromString('0.001'),HedgeState::Drifted);
$assert((new HedgeMonitor())->inspect($drifted)['rehedge_required'],'Hedge drift beyond tolerance must request rehedge.');

$short=new Position(
    'instrument:btc-perp','venue:bybit',Decimal::fromString('1'),Decimal::fromString('101'),
    Decimal::fromString('99'),Decimal::fromString('0'),Decimal::fromString('0'),
    'p-short','paper','FundingCaptureStrategy-v1',$now,$now,null,PositionSide::Short,Decimal::fromString('1')
);
$assert($short->unrealizedPnl()->value()==='2','Short unrealized P&L sign is wrong.');
$eligibility=new FundingSettlementEligibility();
$assert(!$eligibility->eligible($short,$now->modify('-1 second')),'Position must not receive funding before it existed.');
$assert($eligibility->eligible($short,$now->modify('+1 second')),'Open position must be funding-eligible after opening.');

$liq=new LiquidationRiskState(
    Decimal::fromString('101'),Decimal::fromString('70'),Decimal::fromString('0.3'),
    Decimal::fromString('0.15'),Decimal::fromString('100'),LiquidationRiskStatus::Safe,'BYBIT_V5_LINEAR'
);
$policy=new DerivativesRiskPolicy(
    Decimal::fromString('250'),Decimal::fromString('0.1'),1500,Decimal::fromString('3'),Decimal::fromString('0.001')
);
$risk=(new RelativeValueRiskEvaluator())->assess($market,$hedge,$policy,$liq,Decimal::fromString('2'),100);
$assert($risk['accepted'],'Healthy delta-neutral derivative risk state should pass.');

$leg1=new ExecutionLeg(
    'leg-spot',1,'bybit:spot','BTCUSDT',ExecutionSide::Buy,Decimal::fromString('0.1'),'IOC',null,
    Decimal::fromString('100'),Decimal::fromString('0.01'),Decimal::fromString('0.01')
);
$leg2=new ExecutionLeg(
    'leg-perp',2,'bybit:perp','BTCUSDT-PERP',ExecutionSide::Sell,Decimal::fromString('100'),'IOC',null,
    Decimal::fromString('101'),Decimal::fromString('0.01'),Decimal::fromString('0.01')
);
$group=new ExecutionGroup(
    'exec-group-1','FundingCaptureStrategy-v1','opp-1',[$leg1,$leg2],
    ExecutionPolicy::Simultaneous,HedgePolicy::MaintainDeltaNeutral,ExecutionGroupState::Ready,1500,$now,'HG-1008'
);
$assert($group->executionPolicy===ExecutionPolicy::Simultaneous&&$group->maximumUnhedgedTimeMs===1500,'Two-leg execution policy contract drifted.');

$assert(SpotPerpBasisStrategy::version(1,['entry_basis'=>'0.5'])->id()==='SpotPerpBasisStrategy-v1','Basis strategy version id drifted.');
$assert(FundingCaptureStrategy::version(1,['horizon'=>86400])->id()==='FundingCaptureStrategy-v1','Funding strategy version id drifted.');
$assert(CrossVenueFundingStrategy::version(1,['venues'=>['BYBIT','OKX']])->id()==='CrossVenueFundingStrategy-v1','Cross-venue strategy version id drifted.');

echo "Capital Markets Crypto Spot/Perpetual VS2 domain core passed.\n";
