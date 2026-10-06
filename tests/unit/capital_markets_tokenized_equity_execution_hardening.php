<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Execution\CompensationPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionGroupState;
use Domains\CapitalMarkets\Domain\Execution\ExecutionLeg;
use Domains\CapitalMarkets\Domain\Execution\ExecutionPlan;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperOrder;
use Domains\CapitalMarkets\Domain\Execution\PaperOrderState;
use Domains\CapitalMarkets\Domain\Execution\ExecutionModePolicy;
use Domains\CapitalMarkets\Domain\Execution\PaperOrderState;
use Domains\CapitalMarkets\Domain\Execution\PartialFillPolicy;
use Domains\CapitalMarkets\Domain\Portfolio\EconomicExposure;
use Domains\CapitalMarkets\Domain\Execution\ExecutionLegResult;
use Domains\CapitalMarkets\Domain\Service\ExecutionCompensationEngine;
use Domains\CapitalMarkets\Domain\Observability\CapitalMarketsAlertType;
use Domains\CapitalMarkets\Domain\Event\TokenizedEquityEventType;
use Domains\CapitalMarkets\Domain\Risk\TokenizedSecurityRiskProfile;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDetectorConfig;
use Domains\CapitalMarkets\Domain\Value\Decimal;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$assert(ExecutionGroupState::Completed->terminal(),'Completed execution group must be terminal.');
$now=new DateTimeImmutable('2026-10-06T12:00:00+00:00');
$leg1=new ExecutionLeg('leg-1',1,'vm-a','AAPLX',ExecutionSide::Buy,Decimal::fromString('10'),'IOC',null,Decimal::fromString('100'),Decimal::fromString('1'),Decimal::fromString('0.5'));
$leg2=new ExecutionLeg('leg-2',2,'vm-b','AAPLX',ExecutionSide::Sell,Decimal::fromString('10'),'IOC',null,Decimal::fromString('101'),Decimal::fromString('1'),Decimal::fromString('0.5'));
$plan=new ExecutionPlan(
    'plan-1','opp-1','TokenizedEquityRelativeValue-v1',$now,$now->modify('+10 seconds'),
    [$leg1,$leg2],'SEQUENTIAL',PartialFillPolicy::AbortAndCompensate,CompensationPolicy::EmergencyClose,
    1000,400,Decimal::fromString('3'),Decimal::fromString('7'),'risk-1',['res-1']
);
$assert(count($plan->legs)===2,'Execution plan must preserve both arbitrage legs.');
$order=new PaperOrder('ord-1','group-1','leg-1','vm-a','AAPLX',ExecutionSide::Buy,Decimal::fromString('10'),Decimal::fromString('6'),PaperOrderState::PartiallyFilled,$now,$now,$now);
$assert($order->remainingQuantity()->value()==='4','Paper order must preserve remaining quantity after partial fill.');
$compensation=(new ExecutionCompensationEngine())->decide(
    new ExecutionLegResult('leg-1',Decimal::fromString('10'),Decimal::fromString('10'),PaperOrderState::Filled),
    new ExecutionLegResult('leg-2',Decimal::fromString('10'),Decimal::fromString('6'),PaperOrderState::PartiallyFilled,'LIQUIDITY_DISAPPEARED'),
    CompensationPolicy::EmergencyClose
);
$assert($compensation->nextState===ExecutionGroupState::Compensating,'Unhedged first-leg fill must enter compensation.');
$assert($compensation->unhedgedQuantity->value()==='4','Compensation must expose exact unhedged quantity.');
$assert($compensation->policy===CompensationPolicy::EmergencyClose,'Emergency-close policy must be preserved.');
$paperOnly=new ExecutionModePolicy();
$paperOnly->assertPaperOnly();
$assert(!$paperOnly->allowsLive(),'VS1 must default to paper-only execution.');
$assert(CapitalMarketsAlertType::UnhedgedPosition->value==='UNHEDGED_POSITION','Unhedged alert contract drifted.');
$assert(TokenizedEquityEventType::CompensationStarted->value==='CompensationStarted','Compensation event contract drifted.');
$assert(!ExecutionGroupState::Compensating->terminal(),'Compensating execution group must not be terminal.');
$assert(PaperOrderState::PartiallyFilled->terminal()===false,'Partial fill must remain actionable.');
$assert(PartialFillPolicy::AbortAndCompensate->value==='ABORT_AND_COMPENSATE','Partial-fill policy contract drifted.');
$assert(CompensationPolicy::RetrySecondLeg->supportedInVerticalSliceV1(),'Retry second leg is mandatory in VS1.');
$assert(CompensationPolicy::EmergencyClose->supportedInVerticalSliceV1(),'Emergency close is mandatory in VS1.');
$assert(!CompensationPolicy::UseAlternativeMarket->supportedInVerticalSliceV1(),'Alternative market is not mandatory in VS1.');

$risk=new TokenizedSecurityRiskProfile(20,30,40,50,60,20,30,40,70);
$assert($risk->score()===40,'Tokenization risk score must be deterministic integer mean.');

$exposure=new EconomicExposure('AAPL','USD',[
    'underlying'=>Decimal::fromString('10000'),
    'tokenized'=>Decimal::fromString('5000'),
    'hedge'=>Decimal::fromString('-14000'),
]);
$assert($exposure->net()->value()==='1000','Economic exposure must net physical and hedge components exactly.');

$config=new SpreadDetectorConfig(
    'vs1',Decimal::fromString('1'),Decimal::fromString('1'),Decimal::fromString('0.01'),
    2000,500,80,Decimal::fromString('50'),500,Decimal::fromString('0.8'),Decimal::fromString('0.75')
);
$assert($config->minimumExecutionProbability?->value()==='0.75','Execution probability threshold must be explicit and configurable.');

$liveBlocked=false;
try{ (new ExecutionModePolicy(true,false))->assertPaperOnly(); }catch(DomainException){$liveBlocked=true;}
$assert($liveBlocked,'Any live execution switch must fail closed in VS1.');

$failed=false;
try{ new TokenizedSecurityRiskProfile(101,0,0,0,0,0,0,0,0); }catch(InvalidArgumentException){$failed=true;}
$assert($failed,'Out-of-range tokenization risk must fail closed.');

echo "Capital Markets VS1 execution hardening contracts passed.\n";
