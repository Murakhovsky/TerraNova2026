<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Execution\CompensationPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionGroupState;
use Domains\CapitalMarkets\Domain\Execution\PaperOrderState;
use Domains\CapitalMarkets\Domain\Execution\PartialFillPolicy;
use Domains\CapitalMarkets\Domain\Portfolio\EconomicExposure;
use Domains\CapitalMarkets\Domain\Risk\TokenizedSecurityRiskProfile;
use Domains\CapitalMarkets\Domain\Value\Decimal;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$assert(ExecutionGroupState::Completed->terminal(),'Completed execution group must be terminal.');
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

$failed=false;
try{ new TokenizedSecurityRiskProfile(101,0,0,0,0,0,0,0,0); }catch(InvalidArgumentException){$failed=true;}
$assert($failed,'Out-of-range tokenization risk must fail closed.');

echo "Capital Markets VS1 execution hardening contracts passed.\n";
