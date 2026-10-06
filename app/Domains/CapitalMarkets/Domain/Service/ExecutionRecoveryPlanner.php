<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Execution\ExecutionGroupState;
use Domains\CapitalMarkets\Domain\Execution\ExecutionRecoveryAction;
use Domains\CapitalMarkets\Domain\Execution\ExecutionRecoveryDecision;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class ExecutionRecoveryPlanner
{
    public function decide(
        Decimal $requestedQuantity,
        Decimal $firstLegFilled,
        Decimal $secondLegFilled,
        bool $ledgerPosted,
        bool $settlementCompleted,
    ):ExecutionRecoveryDecision{
        if($settlementCompleted){
            return new ExecutionRecoveryDecision(
                ExecutionRecoveryAction::Complete,ExecutionGroupState::Completed,
                $firstLegFilled,$secondLegFilled,Decimal::fromString('0'),'SETTLEMENT_ALREADY_COMPLETED'
            );
        }

        if($ledgerPosted){
            return new ExecutionRecoveryDecision(
                ExecutionRecoveryAction::Complete,ExecutionGroupState::Executing,
                $firstLegFilled,$secondLegFilled,Decimal::fromString('0'),'LEDGER_POSTED_SETTLEMENT_PENDING'
            );
        }

        if(!$firstLegFilled->isPositive()){
            return new ExecutionRecoveryDecision(
                ExecutionRecoveryAction::ExecuteFirstLeg,ExecutionGroupState::Ready,
                $firstLegFilled,$secondLegFilled,Decimal::fromString('0'),'FIRST_LEG_NOT_EXECUTED'
            );
        }

        if($secondLegFilled->compareTo($firstLegFilled)<0){
            $unhedged=DecimalMath::subtract($firstLegFilled,$secondLegFilled);
            $action=$secondLegFilled->isZero()
                ?ExecutionRecoveryAction::ExecuteSecondLeg
                :ExecutionRecoveryAction::Compensate;
            $state=$secondLegFilled->isZero()
                ?ExecutionGroupState::PartiallyExecuted
                :ExecutionGroupState::Compensating;
            return new ExecutionRecoveryDecision(
                $action,$state,$firstLegFilled,$secondLegFilled,$unhedged,
                $secondLegFilled->isZero()?'SECOND_LEG_NOT_EXECUTED':'SECOND_LEG_PARTIAL'
            );
        }

        if($secondLegFilled->compareTo($firstLegFilled)===0){
            return new ExecutionRecoveryDecision(
                ExecutionRecoveryAction::Account,ExecutionGroupState::Executing,
                $firstLegFilled,$secondLegFilled,Decimal::fromString('0'),'LEGS_MATCHED_ACCOUNTING_PENDING'
            );
        }

        return new ExecutionRecoveryDecision(
            ExecutionRecoveryAction::ManualIntervention,ExecutionGroupState::Compensating,
            $firstLegFilled,$secondLegFilled,DecimalMath::subtract($secondLegFilled,$firstLegFilled),
            'SECOND_LEG_OVERFILLED'
        );
    }
}
