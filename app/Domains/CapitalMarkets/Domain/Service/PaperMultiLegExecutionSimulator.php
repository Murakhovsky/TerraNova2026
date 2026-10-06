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
    public function simulateH2(
        MarketOrderBook $buyBook,
        MarketOrderBook $sellBook,
        Decimal $requestedQuantity,
        CompensationPolicy $compensationPolicy=CompensationPolicy::EmergencyClose,
    ):array{
        $buy=$this->prices->executableFill($buyBook,ExecutionSide::Buy,$requestedQuantity);
        $buyQuantity=$buy['filled_quantity'];
        $buyState=$buy['fully_filled']?PaperOrderState::Filled:PaperOrderState::PartiallyFilled;

        try{
            $sell=$this->prices->executableFill($sellBook,ExecutionSide::Sell,$buyQuantity);
            $sellQuantity=$sell['filled_quantity'];
            $sellState=$sell['fully_filled']?PaperOrderState::Filled:PaperOrderState::PartiallyFilled;
        }catch(DomainException){
            $sell=[
                'price'=>Decimal::fromString('0'),
                'filled_quantity'=>Decimal::fromString('0'),
                'remaining_quantity'=>$buyQuantity,
                'notional'=>Decimal::fromString('0'),
                'fully_filled'=>false,
            ];
            $sellQuantity=Decimal::fromString('0');
            $sellState=PaperOrderState::Rejected;
        }

        $decision=$this->compensation->decide(
            new ExecutionLegResult('BUY',$requestedQuantity,$buyQuantity,$buyState),
            new ExecutionLegResult('SELL',$buyQuantity,$sellQuantity,$sellState,$sell['fully_filled']?null:'LIQUIDITY_DISAPPEARED'),
            $compensationPolicy,
        );

        $compensationFill=null;
        $residual=$decision->unhedgedQuantity;
        $finalState=$decision->nextState;

        if($decision->nextState===ExecutionGroupState::Compensating&&$decision->policy===CompensationPolicy::EmergencyClose){
            try{
                $close=$this->prices->executableFill($buyBook,ExecutionSide::Sell,$decision->unhedgedQuantity);
                $compensationFill=[
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
            'buy'=>[
                'price'=>$buy['price'],'filled_quantity'=>$buyQuantity,'notional'=>$buy['notional'],
                'state'=>$buyState,'fully_filled'=>$buy['fully_filled'],
            ],
            'sell'=>[
                'price'=>$sell['price'],'filled_quantity'=>$sellQuantity,'notional'=>$sell['notional'],
                'state'=>$sellState,'fully_filled'=>$sell['fully_filled'],
            ],
            'compensation_policy'=>$decision->policy,
            'compensation'=>$compensationFill,
            'residual_unhedged_quantity'=>$residual,
            'reason'=>$decision->reason,
        ];
    }
}
