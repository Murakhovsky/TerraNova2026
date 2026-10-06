<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\TokenizedEquityVerticalSliceRepositoryInterface;
use Domains\CapitalMarkets\Domain\Execution\ExecutionRecoveryDecision;
use Domains\CapitalMarkets\Domain\Service\ExecutionRecoveryPlanner;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final readonly class TokenizedEquityExecutionRecoveryService
{
    public function __construct(
        private TokenizedEquityVerticalSliceRepositoryInterface $repository,
        private ExecutionRecoveryPlanner $planner,
    ){}

    /** @return array<string,mixed> */
    public function inspect(string $organizationId,string $executionId):array
    {
        $execution=$this->repository->getExecution($organizationId,$executionId);
        if($execution===null)throw new DomainException('EXECUTION_NOT_FOUND');

        $orders=$this->repository->listPaperOrdersForExecution($organizationId,$executionId);
        $fills=$this->repository->listPaperFillsForExecution($organizationId,$executionId);
        $requested=Decimal::fromString((string)($execution['quantity']??'0'));
        if(!$requested->isPositive())throw new DomainException('EXECUTION_RECOVERY_QUANTITY_MISSING');

        $buyOrderId=null;
        $sellOrderId=null;
        foreach($orders as $order){
            $side=(string)($order['side']??'');
            if($side==='BUY'&&$buyOrderId===null)$buyOrderId=(string)($order['id']??'');
            if($side==='SELL'&&$sellOrderId===null)$sellOrderId=(string)($order['id']??'');
        }

        $firstFilled=Decimal::fromString('0');
        $secondFilled=Decimal::fromString('0');
        foreach($fills as $fill){
            $quantity=Decimal::fromString((string)($fill['quantity']??'0'));
            $orderId=(string)($fill['_order_id']??'');
            if($buyOrderId!==null&&$orderId===$buyOrderId){
                $firstFilled=DecimalMath::add($firstFilled,$quantity);
            }elseif($sellOrderId!==null&&$orderId===$sellOrderId){
                $secondFilled=DecimalMath::add($secondFilled,$quantity);
            }
        }

        $ledgerPosted=$this->repository->ledgerTransactionExists($organizationId,$executionId.':settlement');
        $settlementCompleted=(string)($execution['status']??'')==='COMPLETED';
        $decision=$this->planner->decide($requested,$firstFilled,$secondFilled,$ledgerPosted,$settlementCompleted);

        return [
            'execution_id'=>$executionId,
            'status'=>(string)($execution['status']??'UNKNOWN'),
            'checkpoint'=>(string)($execution['checkpoint']??'UNKNOWN'),
            'requested_quantity'=>$requested->value(),
            'first_leg_filled'=>$firstFilled->value(),
            'second_leg_filled'=>$secondFilled->value(),
            'ledger_posted'=>$ledgerPosted,
            'settlement_completed'=>$settlementCompleted,
            'decision'=>$this->decisionArray($decision),
            'orders'=>$orders,
            'fills'=>$fills,
        ];
    }

    /** @return array<string,mixed> */
    private function decisionArray(ExecutionRecoveryDecision $decision):array
    {
        return [
            'action'=>$decision->action->value,
            'state'=>$decision->state->value,
            'first_leg_filled'=>$decision->firstLegFilled->value(),
            'second_leg_filled'=>$decision->secondLegFilled->value(),
            'unhedged_quantity'=>$decision->unhedgedQuantity->value(),
            'reason'=>$decision->reason,
        ];
    }
}
