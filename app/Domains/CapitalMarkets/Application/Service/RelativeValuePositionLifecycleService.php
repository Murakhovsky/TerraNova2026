<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\RelativeValueResearchRepositoryInterface;
use Domains\CapitalMarkets\Domain\Execution\CompensationPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperFill;
use Domains\CapitalMarkets\Domain\Execution\PaperOrder;
use Domains\CapitalMarkets\Domain\Execution\PaperOrderState;
use Domains\CapitalMarkets\Domain\Ledger\LedgerEntry;
use Domains\CapitalMarkets\Domain\Ledger\LedgerTransaction;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\Performance\RelativeValuePerformance;
use Domains\CapitalMarkets\Domain\Portfolio\Position;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Service\PaperMultiLegExecutionSimulator;
use Domains\CapitalMarkets\Domain\Service\RelativeValuePerformanceEngine;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;

final readonly class RelativeValuePositionLifecycleService
{
    public function __construct(
        private CapitalMarketsTradingRepositoryInterface $trading,
        private RelativeValueResearchRepositoryInterface $research,
        private MarketStateRepositoryInterface $marketStates,
        private PaperMultiLegExecutionSimulator $simulator,
        private RelativeValuePerformanceEngine $performance,
    ){}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function close(
        string $organizationId,
        string $executionId,
        string $reason,
        array $options,
    ):array{
        $execution=$this->trading->getExecution($organizationId,$executionId)
            ??throw new DomainException('EXECUTION_NOT_FOUND');
        if(!in_array((string)($execution['status']??''),['OPEN','UNWINDING'],true)){
            if((string)($execution['status']??'')==='CLOSED')return $execution;
            throw new DomainException('EXECUTION_NOT_OPEN');
        }

        $positionRows=$execution['positions']??null;
        if(!is_array($positionRows)||!array_is_list($positionRows)||count($positionRows)!==2){
            throw new DomainException('OPEN_EXECUTION_REQUIRES_TWO_POSITIONS');
        }
        [$firstRow,$secondRow]=$positionRows;
        if(!is_array($firstRow)||!is_array($secondRow))throw new DomainException('POSITION_PAYLOAD_INVALID');

        $first=$this->position($firstRow);
        $second=$this->position($secondRow);
        if($first->quantity->isZero()||$second->quantity->isZero())throw new DomainException('OPEN_POSITION_QUANTITY_REQUIRED');
        if($first->quantity->compareTo($second->quantity)!==0)throw new DomainException('HEDGE_QUANTITY_DRIFT_REQUIRES_REHEDGE');

        $firstState=$this->state($organizationId,$first);
        $secondState=$this->state($organizationId,$second);
        $this->assertMarket($firstState);$this->assertMarket($secondState);

        $firstClose=$first->side===PositionSide::Long?ExecutionSide::Sell:ExecutionSide::Buy;
        $secondClose=$second->side===PositionSide::Long?ExecutionSide::Sell:ExecutionSide::Buy;
        $simulation=$this->simulator->simulateTwoLeg(
            $firstState->orderBook??throw new DomainException('CLOSE_ORDER_BOOK_REQUIRED'),
            $firstClose,
            $secondState->orderBook??throw new DomainException('CLOSE_ORDER_BOOK_REQUIRED'),
            $secondClose,
            $first->quantity,
            CompensationPolicy::EmergencyClose,
        );

        if(!$simulation['first']['fully_filled']||!$simulation['second']['fully_filled']||$simulation['compensation']!==null){
            $payload=[...$execution,'status'=>'UNWINDING','unwind_reason'=>'CLOSE_LIQUIDITY_INSUFFICIENT','exit_reason'=>$reason];
            $this->trading->saveExecution(
                $organizationId,$executionId,(string)$execution['opportunity_id'],'UNWINDING',$payload
            );
            return $payload;
        }

        $now=new DateTimeImmutable();
        $fee1=DecimalMath::multiply($simulation['first']['notional'],$this->requiredDecimal($options,'leg1_close_fee_rate'));
        $fee2=DecimalMath::multiply($simulation['second']['notional'],$this->requiredDecimal($options,'leg2_close_fee_rate'));
        $this->reserveCloseFee($organizationId,$execution,$firstState,$firstRow,$fee1,$executionId.':close1-fee',$now);
        $this->reserveCloseFee($organizationId,$execution,$secondState,$secondRow,$fee2,$executionId.':close2-fee',$now);

        $fill1=$this->persistCloseFill(
            $organizationId,$executionId,'close1',$firstState,$firstClose,$first->quantity,
            $simulation['first']['price'],$fee1,$now
        );
        $fill2=$this->persistCloseFill(
            $organizationId,$executionId,'close2',$secondState,$secondClose,$second->quantity,
            $simulation['second']['price'],$fee2,$now
        );

        [$closed1,$closedRow1]=$this->closePosition($organizationId,$executionId,$first,$firstRow,$firstState,$fill1);
        [$closed2,$closedRow2]=$this->closePosition($organizationId,$executionId,$second,$secondRow,$secondState,$fill2);

        $this->settleVenueClose($organizationId,$first,$firstRow,$firstState,$fill1,$closed1,$executionId.':close1-fee');
        $this->settleVenueClose($organizationId,$second,$secondRow,$secondState,$fill2,$closed2,$executionId.':close2-fee');

        $funding=[];
        foreach([$first,$second] as $position){
            foreach($this->research->listFundingSettlements($organizationId,(string)$position->positionId,10000) as $settlement){
                $funding[]=Decimal::fromString((string)($settlement['gross_cashflow']??'0'));
            }
        }

        $priceAttribution=DecimalMath::add($closed1->realizedPnl,$closed2->realizedPnl);
        $performance=$this->performance->calculate(
            [$closed1,$closed2],$funding,
            Decimal::fromString((string)($options['borrow_cost']??'0')),
            Decimal::fromString((string)($options['network_costs']??'0')),
            $priceAttribution,
        );

        $ledger=$this->closeLedger($executionId,$now,$first,$firstRow,$firstState,$fill1,$closed1,$second,$secondRow,$secondState,$fill2,$closed2);
        $this->trading->saveLedgerTransaction($organizationId,$ledger->id,$ledger->idempotencyKey,[
            'id'=>$ledger->id,'type'=>'RELATIVE_VALUE_POSITION_CLOSE','idempotency_key'=>$ledger->idempotencyKey,
            'posted_at'=>$ledger->postedAt->format(DATE_ATOM),
            'entries'=>array_map(static fn(LedgerEntry $entry):array=>[
                'account'=>$entry->account,'asset_key'=>$entry->assetKey,
                'debit'=>$entry->debit->value(),'credit'=>$entry->credit->value(),
            ],$ledger->entries),
        ]);

        foreach(($execution['reservation_ids']??[]) as $reservation){
            if(!is_string($reservation)||$reservation===(string)($execution['capital_reservation_id']??''))continue;
            try{$this->trading->releasePaperBalanceReservation($organizationId,$reservation);}catch(\Throwable){}
        }
        $capitalReservation=(string)($execution['capital_reservation_id']??'');
        if($capitalReservation!==''){
            $this->trading->completeReservation($organizationId,$capitalReservation,$performance->netPnl->value());
        }

        $payload=[
            ...$execution,
            'status'=>'CLOSED','closed_at'=>$now->format(DATE_ATOM),'exit_reason'=>$reason,
            'positions'=>[$closedRow1,$closedRow2],
            'performance'=>$this->performanceArray($performance),
            'realized_pnl'=>$performance->netPnl->value(),
        ];
        $this->trading->saveExecution($organizationId,$executionId,(string)$execution['opportunity_id'],'CLOSED',$payload);
        $this->savePerformanceObservation($organizationId,$execution,$executionId,$performance,$reason,$now);
        return $payload;
    }

    /** @param array<string,mixed> $row */
    private function position(array $row):Position
    {
        return new Position(
            (string)$row['instrument_id'],(string)$row['venue_id'],
            Decimal::fromString((string)$row['quantity']),Decimal::fromString((string)$row['average_entry_price']),
            Decimal::fromString((string)$row['mark_price']),Decimal::fromString((string)($row['fees']??'0')),
            Decimal::fromString((string)($row['realized_pnl']??'0')),(string)$row['position_id'],
            (string)($row['portfolio_id']??''),(string)($row['strategy_id']??''),
            isset($row['opened_at'])&&$row['opened_at']!==null?new DateTimeImmutable((string)$row['opened_at']):null,
            isset($row['updated_at'])&&$row['updated_at']!==null?new DateTimeImmutable((string)$row['updated_at']):null,
            isset($row['closed_at'])&&$row['closed_at']!==null?new DateTimeImmutable((string)$row['closed_at']):null,
            PositionSide::tryFrom((string)($row['side']??'LONG'))??PositionSide::Long,
            isset($row['contract_multiplier'])&&$row['contract_multiplier']!==null?Decimal::fromString((string)$row['contract_multiplier']):null,
        );
    }

    private function state(string $organizationId,Position $position):MarketState
    {
        return $this->marketStates->get(
            $organizationId,VenueId::fromString($position->venueId),InstrumentId::fromString($position->instrumentId)
        )??throw new DomainException('CLOSE_MARKET_STATE_UNAVAILABLE');
    }

    private function assertMarket(MarketState $state):void
    {
        if(!$state->quality->status->isUsableForDecision())throw new DomainException('CLOSE_MARKET_STATE_UNTRUSTED');
        if($state->marketStatus!==MarketStatus::Open)throw new DomainException('CLOSE_MARKET_NOT_OPEN');
        if($state->bestQuote===null||$state->orderBook===null)throw new DomainException('CLOSE_ORDER_BOOK_REQUIRED');
    }

    /** @param array<string,mixed> $execution @param array<string,mixed> $row */
    private function reserveCloseFee(
        string $organizationId,array $execution,MarketState $state,array $row,Decimal $fee,string $id,DateTimeImmutable $now
    ):void{
        if($fee->isZero()||(string)($row['instrument_kind']??'PERPETUAL')==='SPOT')return;
        $quote=$state->bestQuote?->askPrice->quoteAsset->value()??throw new DomainException('QUOTE_ASSET_REQUIRED');
        if(!$this->trading->reservePaperBalance(
            $organizationId,$id,(string)$execution['opportunity_id'],$state->venueId->value(),$quote,$fee->value(),
            $now->modify('+10 minutes')->format(DATE_ATOM)
        ))throw new DomainException('INSUFFICIENT_CLOSE_FEE_BALANCE');
    }

    private function persistCloseFill(
        string $organizationId,string $executionId,string $name,MarketState $state,ExecutionSide $side,
        Decimal $quantity,Decimal $price,Decimal $fee,DateTimeImmutable $now,
    ):PaperFill{
        $order=new PaperOrder(
            $executionId.':order:'.$name,$executionId,$executionId.':leg:'.$name,$state->key(),$state->instrumentId->value(),
            $side,$quantity,$quantity,PaperOrderState::Filled,$now,$now,$now
        );
        $this->trading->savePaperOrder(
            $organizationId,$order->id,$executionId,$order->legId,$order->state->value,$order->id,[
                'id'=>$order->id,'execution_group_id'=>$executionId,'leg_id'=>$order->legId,'venue_market_id'=>$order->venueMarketId,
                'instrument_id'=>$order->instrumentId,'side'=>$side->value,'requested_quantity'=>$quantity->value(),
                'filled_quantity'=>$quantity->value(),'remaining_quantity'=>'0','state'=>'FILLED',
                'created_at'=>$now->format(DATE_ATOM),'submitted_at'=>$now->format(DATE_ATOM),'filled_at'=>$now->format(DATE_ATOM),
            ]
        );
        $fill=new PaperFill(
            $executionId.':fill:'.$name,$executionId,$state->venueId->value(),$state->instrumentId->value(),
            $side,$quantity,$price,$fee,Decimal::fromString('0'),$now,$executionId.':fill:'.$name
        );
        $this->trading->savePaperFill($organizationId,$fill->id,$order->id,$executionId,$fill->idempotencyKey,[
            'id'=>$fill->id,'venue_id'=>$fill->venueId,'instrument_id'=>$fill->instrumentId,'side'=>$fill->side->value,
            'quantity'=>$fill->quantity->value(),'price'=>$fill->price->value(),'notional'=>$fill->notional()->value(),
            'fee'=>$fill->fee->value(),'slippage'=>'0','filled_at'=>$now->format(DATE_ATOM),'idempotency_key'=>$fill->idempotencyKey,
        ]);
        return $fill;
    }

    /** @param array<string,mixed> $row @return array{0:Position,1:array<string,mixed>} */
    private function closePosition(
        string $organizationId,string $executionId,Position $open,array $row,MarketState $state,PaperFill $close
    ):array{
        $move=$open->side===PositionSide::Long
            ?DecimalMath::subtract($close->price,$open->averageEntryPrice)
            :DecimalMath::subtract($open->averageEntryPrice,$close->price);
        $closePnl=DecimalMath::multiply($open->quantity,$move);
        $realized=DecimalMath::add($open->realizedPnl,$closePnl);
        $fees=DecimalMath::add($open->fees,$close->fee);
        $closed=new Position(
            $open->instrumentId,$open->venueId,Decimal::fromString('0'),$open->averageEntryPrice,$close->price,
            $fees,$realized,$open->positionId,$open->portfolioId,$open->strategyId,$open->openedAt,$close->filledAt,$close->filledAt,
            $open->side,$open->contractMultiplier
        );
        $payload=[
            ...$row,'status'=>'CLOSED','quantity'=>'0','mark_price'=>$close->price->value(),'market_value'=>'0',
            'fees'=>$fees->value(),'realized_pnl'=>$realized->value(),'unrealized_pnl'=>'0',
            'updated_at'=>$close->filledAt->format(DATE_ATOM),'closed_at'=>$close->filledAt->format(DATE_ATOM),
        ];
        $this->trading->savePosition(
            $organizationId,(string)$open->positionId,(string)$open->portfolioId,(string)$open->strategyId,
            $open->instrumentId,$open->venueId,'CLOSED',$payload
        );
        return [$closed,$payload];
    }

    /** @param array<string,mixed> $row */
    private function settleVenueClose(
        string $organizationId,Position $open,array $row,MarketState $state,PaperFill $fill,Position $closed,string $feeReservation
    ):void{
        $kind=(string)($row['instrument_kind']??'PERPETUAL');
        $quote=$state->bestQuote?->askPrice->quoteAsset->value()??throw new DomainException('QUOTE_ASSET_REQUIRED');
        if($kind==='SPOT'){
            if($open->side!==PositionSide::Long)throw new DomainException('PAPER_SPOT_SHORT_CLOSE_NOT_SUPPORTED');
            $base=$state->bestQuote->askPrice->baseAsset->value();
            $this->trading->adjustPaperBalance($organizationId,$state->venueId->value(),$base,DecimalMath::negate($open->quantity)->value());
            $cash=DecimalMath::subtract($fill->notional(),$fill->fee);
            $this->trading->creditPaperBalance($organizationId,$state->venueId->value(),$quote,$cash->value());
            return;
        }

        if(!$fill->fee->isZero())$this->trading->consumePaperBalanceReservation($organizationId,$feeReservation);
        $pricePnl=DecimalMath::subtract($closed->realizedPnl,$open->realizedPnl);
        if(!$pricePnl->isZero()){
            $this->trading->adjustPaperBalance($organizationId,$state->venueId->value(),$quote,$pricePnl->value());
        }
    }

    private function closeLedger(
        string $executionId,DateTimeImmutable $at,
        Position $p1,array $r1,MarketState $s1,PaperFill $f1,Position $c1,
        Position $p2,array $r2,MarketState $s2,PaperFill $f2,Position $c2,
    ):LedgerTransaction{
        $entries=[
            ...$this->closeEntries($p1,$r1,$s1,$f1,$c1),
            ...$this->closeEntries($p2,$r2,$s2,$f2,$c2),
        ];
        return new LedgerTransaction(
            'cm_ledger_'.substr(hash('sha256',$executionId.'|close'),0,40),$executionId.':close',$at,$entries
        );
    }

    /** @param array<string,mixed> $row @return list<LedgerEntry> */
    private function closeEntries(Position $open,array $row,MarketState $state,PaperFill $fill,Position $closed):array
    {
        $kind=(string)($row['instrument_kind']??'PERPETUAL');
        $quote=$state->bestQuote?->askPrice->quoteAsset->value()??throw new DomainException('QUOTE_ASSET_REQUIRED');
        $entries=[];
        if($kind==='SPOT'){
            $base=$state->bestQuote->askPrice->baseAsset->value();
            $entries[] = new LedgerEntry('external:'.$open->venueId.':inventory',$fill->quantity,Decimal::fromString('0'),$base);
            $entries[] = new LedgerEntry('venue:'.$open->venueId.':inventory',Decimal::fromString('0'),$fill->quantity,$base);
            $entries[] = new LedgerEntry('venue:'.$open->venueId.':cash',$fill->notional(),Decimal::fromString('0'),$quote);
            $entries[] = new LedgerEntry('external:'.$open->venueId.':cash',Decimal::fromString('0'),$fill->notional(),$quote);
        }else{
            $memo='POSITION:'.$open->instrumentId;
            if($open->side===PositionSide::Long){
                $entries[] = new LedgerEntry('external:perp_position',$fill->quantity,Decimal::fromString('0'),$memo);
                $entries[] = new LedgerEntry('perp_position_long:'.$open->venueId,Decimal::fromString('0'),$fill->quantity,$memo);
            }else{
                $entries[] = new LedgerEntry('perp_position_short:'.$open->venueId,$fill->quantity,Decimal::fromString('0'),$memo);
                $entries[] = new LedgerEntry('external:perp_position',Decimal::fromString('0'),$fill->quantity,$memo);
            }
            $pnl=DecimalMath::subtract($closed->realizedPnl,$open->realizedPnl);
            if($pnl->isPositive()){
                $entries[] = new LedgerEntry('venue:'.$open->venueId.':cash',$pnl,Decimal::fromString('0'),$quote);
                $entries[] = new LedgerEntry('realized_pnl_income:'.$open->strategyId,Decimal::fromString('0'),$pnl,$quote);
            }elseif($pnl->isNegative()){
                $loss=DecimalMath::abs($pnl);
                $entries[] = new LedgerEntry('realized_pnl_loss:'.$open->strategyId,$loss,Decimal::fromString('0'),$quote);
                $entries[] = new LedgerEntry('venue:'.$open->venueId.':cash',Decimal::fromString('0'),$loss,$quote);
            }
        }
        if(!$fill->fee->isZero()){
            $entries[] = new LedgerEntry('trading_fee:'.$open->venueId,$fill->fee,Decimal::fromString('0'),$quote);
            $entries[] = new LedgerEntry('venue:'.$open->venueId.':cash',Decimal::fromString('0'),$fill->fee,$quote);
        }
        return $entries;
    }

    private function savePerformanceObservation(
        string $organizationId,array $execution,string $executionId,RelativeValuePerformance $performance,string $reason,DateTimeImmutable $at
    ):void{
        $hypothesis=(string)($execution['hypothesis']??'H4');
        $fingerprint=hash('sha256',implode('|',[$organizationId,$hypothesis,'PERFORMANCE',$executionId]));
        $this->trading->saveHypothesisObservation(
            $organizationId,'cm_obs_'.substr($fingerprint,0,40),$hypothesis,'PERFORMANCE',$at->format(DATE_ATOM),$fingerprint,
            ['opportunity_id'=>(string)($execution['opportunity_id']??''),'execution_id'=>$executionId,
             'detected'=>true,'executable'=>true,'realized'=>true,
             'expected_pnl'=>(string)($execution['expected_pnl']??'0'),'realized_pnl'=>$performance->netPnl->value(),
             'reason'=>$reason,'performance'=>$this->performanceArray($performance)]
        );
    }

    /** @return array<string,mixed> */
    private function performanceArray(RelativeValuePerformance $p):array{return [
        'spot_price_pnl'=>$p->spotPricePnl->value(),'derivative_price_pnl'=>$p->derivativePricePnl->value(),
        'funding_pnl'=>$p->fundingPnl->value(),'trading_fees'=>$p->tradingFees->value(),
        'borrow_cost'=>$p->borrowCost->value(),'network_costs'=>$p->networkCosts->value(),
        'basis_attribution'=>$p->basisAttribution->value(),'basis_is_attribution_only'=>true,'net_pnl'=>$p->netPnl->value(),
    ];}

    private function requiredDecimal(array $options,string $key):Decimal
    {
        if(!array_key_exists($key,$options))throw new InvalidArgumentException($key.' is required.');
        $value=Decimal::fromString((string)$options[$key]);
        if($value->isNegative())throw new InvalidArgumentException($key.' cannot be negative.');
        return $value;
    }
}
