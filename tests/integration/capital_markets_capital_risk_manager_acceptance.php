<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Allocation\AllocationPolicy;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioRiskState;
use Domains\CapitalMarkets\Domain\Risk\RiskEnvelope;
use Domains\CapitalMarkets\Domain\Risk\RiskEnvelopeLevel;
use Domains\CapitalMarkets\Domain\Risk\RiskLimit;
use Domains\CapitalMarkets\Domain\Risk\RiskLimitType;
use Domains\CapitalMarkets\Domain\Service\CapitalAllocationEngine;
use Domains\CapitalMarkets\Domain\Service\EconomicExposureEngine;
use Domains\CapitalMarkets\Domain\Service\PortfolioRiskEngine;
use Domains\CapitalMarkets\Domain\Service\PortfolioStressEngine;
use Domains\CapitalMarkets\Domain\Service\RiskLimitEvaluator;
use Domains\CapitalMarkets\Domain\Service\RiskPolicyHierarchy;
use Domains\CapitalMarkets\Domain\Stress\PortfolioStressScenario;
use Domains\CapitalMarkets\Domain\Value\Decimal;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$positions=[
 ['instrument_id'=>'AAPL','underlying_key'=>'AAPL','notional'=>'10000','side'=>'LONG','venue'=>'KRAKEN','strategy'=>'TOKENIZED','asset'=>'AAPL','counterparty'=>'KRAKEN','currency'=>'USD','relationship_valid'=>true],
 ['instrument_id'=>'AAPLx','underlying_key'=>'AAPL','notional'=>'5000','side'=>'LONG','venue'=>'BYBIT','strategy'=>'TOKENIZED','asset'=>'AAPLx','counterparty'=>'BYBIT','currency'=>'USDT','relationship_valid'=>true],
 ['instrument_id'=>'AAPL-PERP','underlying_key'=>'AAPL','notional'=>'12000','side'=>'SHORT','venue'=>'BYBIT','strategy'=>'TOKENIZED','asset'=>'AAPL-PERP','counterparty'=>'BYBIT','currency'=>'USDT','relationship_valid'=>true],
 ['instrument_id'=>'BTC-SPOT','underlying_key'=>'BTC','notional'=>'20000','side'=>'LONG','venue'=>'BINANCE','strategy'=>'BASIS','asset'=>'BTC','counterparty'=>'BINANCE','currency'=>'USDT','relationship_valid'=>true],
 ['instrument_id'=>'BTC-PERP-BYBIT','underlying_key'=>'BTC','notional'=>'15000','side'=>'SHORT','venue'=>'BYBIT','strategy'=>'BASIS','asset'=>'BTC-PERP','counterparty'=>'BYBIT','currency'=>'USDT','relationship_valid'=>true],
 ['instrument_id'=>'ETH-SPOT','underlying_key'=>'ETH','notional'=>'10000','side'=>'LONG','venue'=>'KRAKEN','strategy'=>'FUNDING','asset'=>'ETH','counterparty'=>'KRAKEN','currency'=>'USD','relationship_valid'=>true],
 ['instrument_id'=>'ETH-PERP','underlying_key'=>'ETH','notional'=>'7000','side'=>'SHORT','venue'=>'OKX','strategy'=>'FUNDING','asset'=>'ETH-PERP','counterparty'=>'OKX','currency'=>'USDT','relationship_valid'=>true],
 ['instrument_id'=>'SOL-SPOT','underlying_key'=>'SOL','notional'=>'5000','side'=>'LONG','venue'=>'BINANCE','strategy'=>'FUNDING','asset'=>'SOL','counterparty'=>'BINANCE','currency'=>'USDT','relationship_valid'=>true],
 ['instrument_id'=>'USDT-COLLATERAL','underlying_key'=>'USDT','notional'=>'8000','side'=>'LONG','venue'=>'BYBIT','strategy'=>'FUNDING','asset'=>'USDT','counterparty'=>'TETHER','currency'=>'USDT','relationship_valid'=>true],
 ['instrument_id'=>'BTC-PERP-OKX','underlying_key'=>'BTC','notional'=>'3000','side'=>'SHORT','venue'=>'OKX','strategy'=>'BASIS','asset'=>'BTC-PERP','counterparty'=>'OKX','currency'=>'USDT','relationship_valid'=>true],
];

$exposure=(new EconomicExposureEngine())->snapshot('paper-master',$positions);
$assert($exposure->grossExposure->value()==='95000','Acceptance: gross exposure must be 95k.');
$assert($exposure->netExposure->value()==='21000','Acceptance: net economic exposure must be 21k.');
$assert($exposure->byVenue['BYBIT']->value()==='40000','Acceptance: BYBIT counterparty/venue concentration must remain gross.');
$assert($exposure->byUnderlying['AAPL']->value()==='3000','Acceptance: AAPL economic net must be +3k.');
$assert($exposure->byUnderlying['BTC']->value()==='2000','Acceptance: BTC economic net must be +2k.');

$riskEngine=new PortfolioRiskEngine(new RiskLimitEvaluator(),new RiskPolicyHierarchy());
$envelope=new RiskEnvelope('env','paper-master','v1',[
 new RiskLimit('gross_exposure',RiskEnvelopeLevel::Portfolio,RiskLimitType::Absolute,Decimal::fromString('100000'),null,true),
 new RiskLimit('venue_exposure',RiskEnvelopeLevel::Venue,RiskLimitType::Absolute,Decimal::fromString('45000'),'BYBIT',true),
 new RiskLimit('leverage',RiskEnvelopeLevel::Portfolio,RiskLimitType::Absolute,Decimal::fromString('1.2'),null,true),
]);
$risk=$riskEngine->assess($envelope,[
 'equity'=>Decimal::fromString('100000'),
 'capital'=>Decimal::fromString('100000'),
 'gross_exposure'=>$exposure->grossExposure,
 'venue_exposure:BYBIT'=>$exposure->byVenue['BYBIT'],
 'leverage'=>Decimal::fromString('0.95'),
]);
$venueHeadroom=$risk['headroom']['venue_exposure:VENUE:BYBIT']??null;
$assert($venueHeadroom!==null&&$venueHeadroom->headroom->value()==='5000','Acceptance: BYBIT hard headroom must be exactly 5k.');

$opportunities=[
 ['opportunity_id'=>'A','strategy_version_id'=>'TOKENIZED','requested_capital'=>'20000','expected_net_return'=>'0.020','confidence'=>'0.95','execution_probability'=>'0.95','capacity'=>'20000','risk'=>'1','concentration_penalty'=>'0','liquidity_penalty'=>'0','strategy_score'=>'95','portfolio_risk_improvement'=>'0'],
 ['opportunity_id'=>'B','strategy_version_id'=>'BASIS','requested_capital'=>'12000','expected_net_return'=>'0.014','confidence'=>'0.90','execution_probability'=>'0.90','capacity'=>'12000','risk'=>'1','concentration_penalty'=>'0','liquidity_penalty'=>'0','strategy_score'=>'90','portfolio_risk_improvement'=>'1'],
 ['opportunity_id'=>'C','strategy_version_id'=>'FUNDING','requested_capital'=>'10000','expected_net_return'=>'0.012','confidence'=>'0.90','execution_probability'=>'0.90','capacity'=>'10000','risk'=>'1','concentration_penalty'=>'0','liquidity_penalty'=>'0','strategy_score'=>'88','strategy_correlations'=>['BASIS'=>'0.90']],
 ['opportunity_id'=>'D','strategy_version_id'=>'BASIS','requested_capital'=>'10000','expected_net_return'=>'0.010','confidence'=>'0.85','execution_probability'=>'0.90','capacity'=>'10000','risk'=>'1','concentration_penalty'=>'0','liquidity_penalty'=>'0','strategy_score'=>'86','strategy_capital_headroom'=>'22000'],
 ['opportunity_id'=>'E','strategy_version_id'=>'TOKENIZED','requested_capital'=>'8000','expected_net_return'=>'0.009','confidence'=>'0.80','execution_probability'=>'0.85','capacity'=>'8000','risk'=>'1','concentration_penalty'=>'0.10','liquidity_penalty'=>'0','strategy_score'=>'82'],
 ['opportunity_id'=>'F','strategy_version_id'=>'FUNDING','requested_capital'=>'8000','expected_net_return'=>'0.008','confidence'=>'0.85','execution_probability'=>'0.85','capacity'=>'8000','risk'=>'1','concentration_penalty'=>'0','liquidity_penalty'=>'0.10','strategy_score'=>'80'],
 ['opportunity_id'=>'G','strategy_version_id'=>'BASIS','requested_capital'=>'5000','expected_net_return'=>'0.007','confidence'=>'0.80','execution_probability'=>'0.80','capacity'=>'5000','risk'=>'1','concentration_penalty'=>'0','liquidity_penalty'=>'0','strategy_score'=>'78','strategy_capital_headroom'=>'22000'],
 ['opportunity_id'=>'H','strategy_version_id'=>'FUNDING','requested_capital'=>'5000','expected_net_return'=>'0.006','confidence'=>'0.75','execution_probability'=>'0.80','capacity'=>'5000','risk'=>'1','concentration_penalty'=>'0','liquidity_penalty'=>'0','strategy_score'=>'75'],
];

$policy=new AllocationPolicy(
 'manager-acceptance','v1','SCORE_BASED','BALANCED',['score_multiplier'=>'1'],
 ['high_correlation_threshold'=>'0.80','high_correlation_allocation_multiplier'=>'0.50','risk_improvement_weight'=>'1']
);
$allocator=new CapitalAllocationEngine();
$plan=$allocator->allocate(
 'paper-master',Decimal::fromString('60000'),$opportunities,$policy,PortfolioRiskState::Normal,['A'=>'5000']
);
$repeat=$allocator->allocate(
 'paper-master',Decimal::fromString('60000'),$opportunities,$policy,PortfolioRiskState::Normal,['A'=>'5000']
);

$assert($plan->inputFingerprint===$repeat->inputFingerprint,'Acceptance: identical inputs and policy must reproduce the same allocation fingerprint.');
$assert($plan->id===$repeat->id,'Acceptance: deterministic allocation plan ID must be reproducible.');

$totalApproved=Decimal::fromString('0');$byId=[];
foreach($plan->allocations as $item){
 $totalApproved=Domains\CapitalMarkets\Domain\Value\DecimalMath::add($totalApproved,$item->approvedCapital);
 $byId[$item->opportunityId]=$item;
}
$assert($totalApproved->compareTo(Decimal::fromString('60000'))<=0,'Acceptance: allocation must not oversubscribe 60k available capital.');
$assert(($byId['A']??null)!==null&&$byId['A']->approvedCapital->compareTo(Decimal::fromString('5000'))<=0,'Acceptance B: highest-return A must be reduced by BYBIT hard headroom.');
$assert($plan->allocations[0]->opportunityId==='B','Acceptance E: risk-reducing B must outrank standalone-return A in portfolio context.');

$stress=(new PortfolioStressEngine())->run(
 new PortfolioStressScenario('combined','BTC -20% + Bybit unavailable',['BTC'=>'-0.20','VENUE:BYBIT'=>'OFFLINE']),
 Decimal::fromString('100000'),
 [
  ['position_id'=>'btc-spot','asset'=>'BTC','venue'=>'BINANCE','side'=>'LONG','notional'=>'20000'],
  ['position_id'=>'btc-perp-bybit','asset'=>'BTC','venue'=>'BYBIT','side'=>'SHORT','notional'=>'15000','initial_margin'=>'4000','available_margin'=>'1000'],
  ['position_id'=>'btc-perp-okx','asset'=>'BTC','venue'=>'OKX','side'=>'SHORT','notional'=>'3000'],
 ]
);
$assert(in_array('BYBIT',$stress->venuesAffected,true),'Acceptance H: venue-down stress must identify BYBIT.');
$assert(in_array('VENUE_OPERATIONAL_UNAVAILABLE:BYBIT',$stress->riskLimitsBreached,true),'Acceptance H: venue outage must register operational breach.');
$assert($stress->capitalRemaining->isPositive(),'Acceptance H: stress result must report remaining capital.');

echo "CM-CAPITAL-RISK Manager Acceptance financial scenario passed.\n";
