<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Execution\CompensationPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionGroupState;
use Domains\CapitalMarkets\Domain\Execution\ExecutionLegResult;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperOrderState;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use DomainException;

final readonly class PaperMultiLegExecutionSimulator
{
    public function __construct(
        private ExecutablePriceCalculator $prices,
        private ExecutionCompensationEngine $compensation,
    ){}

    /** @return array<string,mixed> */
    public function simulateTwoLeg(
        MarketOrderBook $firstBook,
        ExecutionSide $firstSide,
        MarketOrderBook $secondBook,
        ExecutionSide $secondSide,
        Decimal $requestedQuantity,
        CompensationPolicy $compensationPolicy=CompensationPolicy::EmergencyClose,
    ):array{
        if($firstSide===$secondSide)throw new DomainException('TWO_LEG_RELATIVE_VALUE_REQUIRES_OPPOSING_SIDES');

        $first=$this->prices->executableFill($firstBook,$firstSide,$requestedQuantity);
        $firstQuantity=$first['filled_quantity'];
        $firstState=$first['fully_filled']?PaperOrderState::Filled:PaperOrderState::PartiallyFilled;

        try{
            $second=$this->prices->executableFill($secondBook,$secondSide,$firstQuantity);
            $secondQuantity=$second['filled_quantity'];
            $secondState=$second['fully_filled']?PaperOrderState::Filled:PaperOrderState::PartiallyFilled;
        }catch(DomainException){
            $second=[
                'price'=>Decimal::fromString('0'),
                'filled_quantity'=>Decimal::fromString('0'),
                'remaining_quantity'=>$firstQuantity,
                'notional'=>Decimal::fromString('0'),
                'fully_filled'=>false,
            ];
            $secondQuantity=Decimal::fromString('0');
            $secondState=PaperOrderState::Rejected;
        }

        $decision=$this->compensation->decide(
            new ExecutionLegResult('LEG1',$requestedQuantity,$firstQuantity,$firstState),
            new ExecutionLegResult('LEG2',$firstQuantity,$secondQuantity,$secondState,$second['fully_filled']?null:'LIQUIDITY_DISAPPEARED'),
            $compensationPolicy,
        );

        $compensationFill=null;
        $residual=$decision->unhedgedQuantity;
        $finalState=$decision->nextState;

        if($decision->nextState===ExecutionGroupState::Compensating&&$decision->policy===CompensationPolicy::EmergencyClose){
            try{
                $closeSide=$firstSide===ExecutionSide::Buy?ExecutionSide::Sell:ExecutionSide::Buy;
                $close=$this->prices->executableFill($firstBook,$closeSide,$decision->unhedgedQuantity);
                $compensationFill=[
                    'side'=>$closeSide,
                    'price'=>$close['price'],
                    'filled_quantity'=>$close['filled_quantity'],
                    'notional'=>$close['notional'],
                    'fully_filled'=>$close['fully_filled'],
                ];
                $residual=DecimalMath::subtract($decision->unhedgedQuantity,$close['filled_quantity']);
                $finalState=$residual->isZero()?ExecutionGroupState::Completed:ExecutionGroupState::Compensating;
            }catch(DomainException){
                $finalState=ExecutionGroupState::Compensating;
            }
        }

        return [
            'state'=>$finalState,
            'requested_quantity'=>$requestedQuantity,
            'first'=>[
                'side'=>$firstSide,'price'=>$first['price'],'filled_quantity'=>$firstQuantity,'notional'=>$first['notional'],
                'state'=>$firstState,'fully_filled'=>$first['fully_filled'],
            ],
            'second'=>[
                'side'=>$secondSide,'price'=>$second['price'],'filled_quantity'=>$secondQuantity,'notional'=>$second['notional'],
                'state'=>$secondState,'fully_filled'=>$second['fully_filled'],
            ],
            'compensation_policy'=>$decision->policy,
            'compensation'=>$compensationFill,
            'residual_unhedged_quantity'=>$residual,
            'reason'=>$decision->reason,
        ];
    }

    /** @return array<string,mixed> */
    public function simulateH2(
        MarketOrderBook $buyBook,
        MarketOrderBook $sellBook,
        Decimal $requestedQuantity,
        CompensationPolicy $compensationPolicy=CompensationPolicy::EmergencyClose,
    ):array{
        $result=$this->simulateTwoLeg(
            $buyBook,ExecutionSide::Buy,$sellBook,ExecutionSide::Sell,$requestedQuantity,$compensationPolicy
        );
        // Compatibility shape for VS1.
        $result['buy']=$result['first'];
        $result['sell']=$result['second'];
        return $result;
    }
}
