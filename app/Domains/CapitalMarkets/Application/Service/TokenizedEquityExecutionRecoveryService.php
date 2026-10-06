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
    public function resumeSettlement(string $organizationId,string $executionId):array
    {
        $state=$this->inspect($organizationId,$executionId);
        $execution=$this->repository->getExecution($organizationId,$executionId);
        if($execution===null)throw new DomainException('EXECUTION_NOT_FOUND');

        if(($state['settlement_completed']??false)===true)return $execution;
        if(($state['ledger_posted']??false)!==true)throw new DomainException('RECOVERY_LEDGER_NOT_POSTED');

        $buyFill=$execution['buy_fill']??null;
        $sellFill=$execution['sell_fill']??null;
        if(!is_array($buyFill)||!is_array($sellFill)){
            throw new DomainException('RECOVERY_FILL_CONTEXT_MISSING');
        }

        $reservationId=$this->required($execution,'reservation_id');
        $buyCashReservation=$this->required($execution,'buy_cash_reservation_id');
        $sellInventoryReservation=$this->required($execution,'sell_inventory_reservation_id');
        $buyVenue=$this->required($execution,'buy_venue_id');
        $sellVenue=$this->required($execution,'sell_venue_id');
        $buyInstrument=$this->required($execution,'buy_instrument_id');
        $quoteAsset=$this->required($execution,'quote_asset');

        $buyQuantity=Decimal::fromString((string)($buyFill['quantity']??'0'));
        $sellNotional=Decimal::fromString((string)($sellFill['notional']??'0'));
        $sellFee=Decimal::fromString((string)($sellFill['fee']??'0'));
        $sellCash=DecimalMath::subtract($sellNotional,$sellFee);
        $realizedPnl=Decimal::fromString((string)($execution['realized_pnl']??'0'));

        $this->repository->settlePaperExecution(
            $organizationId,$executionId,$reservationId,$buyCashReservation,$sellInventoryReservation,
            $buyVenue,$buyInstrument,$buyQuantity->value(),$sellVenue,$quoteAsset,$sellCash->value(),$realizedPnl->value()
        );

        $completed=[
            ...$execution,
            'status'=>'COMPLETED',
            'checkpoint'=>'SETTLED',
            'recovered'=>true,
            'recovered_at'=>(new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $opportunityId=$this->required($execution,'opportunity_id');
        $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'COMPLETED',$completed);

        $opportunity=$this->repository->getOpportunity($organizationId,$opportunityId);
        if(is_array($opportunity)){
            $candidate=$opportunity['candidate']??[];
            $fingerprint=hash('sha256',implode('|',[$organizationId,'H2','EXECUTION',$opportunityId,$executionId]));
            $this->repository->saveHypothesisObservation(
                $organizationId,'cm_obs_'.substr($fingerprint,0,40),'H2','EXECUTION',
                (new \DateTimeImmutable())->format(DATE_ATOM),$fingerprint,[
                    'market_pair_id'=>(string)($candidate['market_pair_id']??''),
                    'candidate_id'=>(string)($candidate['id']??''),
                    'opportunity_id'=>$opportunityId,'execution_id'=>$executionId,
                    'detected'=>true,'executable'=>true,'realized'=>true,
                    'expected_pnl'=>(string)($opportunity['expected_pnl']??'0'),
                    'realized_pnl'=>$realizedPnl->value(),
                    'reason'=>null,
                    'edge_capture_ratio'=>(string)($execution['edge_capture_ratio']??'0'),
                    'recovered'=>true,
                ]
            );
        }

        return $completed;
    }

    /** @param array<string,mixed> $payload */
    private function required(array $payload,string $key):string
    {
        $value=trim((string)($payload[$key]??''));
        if($value==='')throw new DomainException('RECOVERY_CONTEXT_MISSING: '.$key);
        return $value;
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
