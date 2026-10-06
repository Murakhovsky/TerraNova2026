<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Execution\ExecutionRecoveryAction;
use Domains\CapitalMarkets\Domain\Execution\ExecutionGroupState;
use Domains\CapitalMarkets\Domain\Service\ExecutionRecoveryPlanner;
use Domains\CapitalMarkets\Domain\Value\Decimal;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$planner=new ExecutionRecoveryPlanner();

$first=$planner->decide(
    Decimal::fromString('10'),Decimal::fromString('0'),Decimal::fromString('0'),false,false
);
$assert($first->action===ExecutionRecoveryAction::ExecuteFirstLeg,'Fresh execution must start with first leg.');

$second=$planner->decide(
    Decimal::fromString('10'),Decimal::fromString('10'),Decimal::fromString('0'),false,false
);
$assert($second->action===ExecutionRecoveryAction::ExecuteSecondLeg,'Persisted first-leg fill must resume at second leg.');
$assert($second->unhedgedQuantity->value()==='10','Recovery must expose first-leg unhedged quantity.');

$partial=$planner->decide(
    Decimal::fromString('10'),Decimal::fromString('10'),Decimal::fromString('6'),false,false
);
$assert($partial->action===ExecutionRecoveryAction::Compensate,'Partial second leg must enter compensation.');
$assert($partial->state===ExecutionGroupState::Compensating,'Partial second leg must persist compensating state.');
$assert($partial->unhedgedQuantity->value()==='4','Compensation quantity must be exact.');

$account=$planner->decide(
    Decimal::fromString('10'),Decimal::fromString('10'),Decimal::fromString('10'),false,false
);
$assert($account->action===ExecutionRecoveryAction::Account,'Matched legs must resume at accounting, not trade again.');

$settle=$planner->decide(
    Decimal::fromString('10'),Decimal::fromString('10'),Decimal::fromString('10'),true,false
);
$assert($settle->action===ExecutionRecoveryAction::Complete,'Ledger-posted execution must only finish settlement.');

$done=$planner->decide(
    Decimal::fromString('10'),Decimal::fromString('10'),Decimal::fromString('10'),true,true
);
$assert($done->state===ExecutionGroupState::Completed,'Settled execution must be terminal.');

echo "Capital Markets phased execution recovery planner passed.\n";
