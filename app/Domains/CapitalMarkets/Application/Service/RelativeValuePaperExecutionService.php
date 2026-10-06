<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\RelativeValueResearchRepositoryInterface;
use Domains\CapitalMarkets\Domain\Execution\CompensationPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionLeg;
use Domains\CapitalMarkets\Domain\Execution\ExecutionPlan;
use Domains\CapitalMarkets\Domain\Execution\ExecutionPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperFill;
use Domains\CapitalMarkets\Domain\Execution\PaperOrder;
use Domains\CapitalMarkets\Domain\Execution\PartialFillPolicy;
use Domains\CapitalMarkets\Domain\Ledger\LedgerEntry;
use Domains\CapitalMarkets\Domain\Ledger\LedgerTransaction;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeGroup;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeLeg;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeState;
use Domains\CapitalMarkets\Domain\Portfolio\Position;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Service\HedgeMonitor;
use Domains\CapitalMarkets\Domain\Service\PaperMultiLegExecutionSimulator;
use Domains\CapitalMarkets\Domain\Service\PositionProjector;
use Domains\CapitalMarkets\Domain\Service\RelativeValuePerformanceEngine;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;

final readonly class RelativeValuePaperExecutionService
{
    public function __construct(
        private MarketStateRepositoryInterface $marketStates,
        private CapitalMarketsTradingRepositoryInterface $trading,
        private RelativeValueResearchRepositoryInterface $research,
        private PaperMultiLegExecutionSimulator $simulator,
        private PositionProjector $positions,
        private HedgeMonitor $hedges,
        private RelativeValuePerformanceEngine $performance,
    ){}

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function execute(string $organizationId,string $opportunityId,array $options):array
    {
        $opportunity=$this->trading->getOpportunity($organizationId,$opportunityId)
            ??throw new DomainException('OPPORTUNITY_NOT_FOUND');
        $existing=$this->trading->getExecutionForOpportunity($organizationId,$opportunityId);
        if($existing!==null){
            if(in_array((string)($existing['status']??''),['OPEN','COMPLETED_COMPENSATED','FAILED','INVALIDATED','CLOSED'],true))return $existing;
            throw new DomainException('EXECUTION_RECOVERY_REQUIRED');
        }

        $hypothesis=(string)($opportunity['hypothesis']??'');
        if(!in_array($hypothesis,['H4','H5','H6'],true))throw new DomainException('RELATIVE_VALUE_EXECUTION_SUPPORTS_H4_H5_H6_ONLY');
        if((string)($opportunity['status']??'')!=='APPROVED')throw new DomainException('OPPORTUNITY_NOT_APPROVED');

        $now=new DateTimeImmutable();
        $expires=new DateTimeImmutable((string)($opportunity['expires_at']??'@0'));
        if($now >= $expires)throw new DomainException('OPPORTUNITY_EXPIRED');

        $legs=$opportunity['legs']??null;
        if(!is_array($legs)||!array_is_list($legs)||count($legs)!==2)throw new DomainException('TWO_LEG_OPPORTUNITY_REQUIRED');
        $risk=$opportunity['risk']??null;
        if(!is_array($risk)||array_is_list($risk))throw new DomainException('RISK_ASSESSMENT_REQUIRED');
        $requested=Decimal::fromString((string)($risk['approved_quantity']??'0'));
        if(!$requested->isPositive())throw new DomainException('RISK_APPROVED_QUANTITY_REQUIRED');

        [$first,$firstState,$firstSide]=$this->resolveLeg($organizationId,$legs[0]);
        [$second,$secondState,$secondSide]=$this->resolveLeg($organizationId,$legs[1]);
        if($firstSide===$secondSide)throw new DomainException('TWO_LEG_SIDES_MUST_OPPOSE');
        $this->assertRevalidation($hypothesis,$firstState,$secondState);

        if($firstState->orderBook===null||$secondState->orderBook===null){
            return $this->invalidated($organizationId,$opportunityId,$hypothesis,$now,'ORDER_BOOK_DEPTH_REQUIRED');
        }

        $maxUnhedgedMs=$this->requiredInt($options,'maximum_unhedged_time_ms');
        $simulatedLegLatencyMs=$this->int($options,'simulated_leg_latency_ms',0);
        if($simulatedLegLatencyMs>$maxUnhedgedMs){
            return $this->invalidated($organizationId,$opportunityId,$hypothesis,$now,'HEDGE_BREACH');
        }

        $simulation=$this->simulator->simulateTwoLeg(
            $firstState->orderBook,$firstSide,$secondState->orderBook,$secondSide,$requested,CompensationPolicy::EmergencyClose
        );
        if(!$simulation['residual_unhedged_quantity']->isZero()){
            return $this->invalidated($organizationId,$opportunityId,$hypothesis,$now,'HEDGE_BREACH');
        }

        $firstQty=$simulation['first']['filled_quantity'];
        $secondQty=$simulation['second']['filled_quantity'];
        $matched=$firstQty->compareTo($secondQty)<=0?$firstQty:$secondQty;
        $comp=$simulation['compensation'];
        $firstFeeRate=$this->requiredDecimal($options,'leg1_fee_rate');
        $secondFeeRate=$this->requiredDecimal($options,'leg2_fee_rate');
        $firstFee=DecimalMath::multiply($simulation['first']['notional'],$firstFeeRate);
        $secondFee=DecimalMath::multiply($simulation['second']['notional'],$secondFeeRate);
        $compFee=$comp===null?Decimal::fromString('0'):DecimalMath::multiply($comp['notional'],$firstFeeRate);

        $executionId='cm_exec_'.bin2hex(random_bytes(12));
        $holding=(int)($opportunity['economics']['holding_horizon_seconds']??3600);
        $holdUntil=$now->modify('+'.max(1,$holding).' seconds');
        $capitalRequired=Decimal::fromString((string)($opportunity['required_capital']??'0'));
        if(!$capitalRequired->isPositive())throw new DomainException('CAPITAL_REQUIRED_INVALID');
        $capitalReservation='cm_res_'.substr(hash('sha256',$executionId.'|capital'),0,40);
        if(!$this->trading->reserveCapital($organizationId,$capitalReservation,$opportunityId,$capitalRequired->value(),$holdUntil->format(DATE_ATOM))){
            return $this->invalidated($organizationId,$opportunityId,$hypothesis,$now,'INSUFFICIENT_PAPER_CAPITAL');
        }

        $reservations=[$capitalReservation];
        try{
            $reservations=[
                ...$reservations,
                ...$this->reserveLegResources(
                    $organizationId,$opportunityId,$executionId,'leg1',$first,$firstState,$firstSide,
                    $simulation['first']['notional'],$firstFee,$matched,$options,$holdUntil
                ),
                ...$this->reserveLegResources(
                    $organizationId,$opportunityId,$executionId,'leg2',$second,$secondState,$secondSide,
                    $simulation['second']['notional'],$secondFee,$matched,$options,$holdUntil
                ),
            ];
            if(!$compFee->isZero()){
                $feeReservation=$executionId.':comp-fee';
                $quote=$this->quoteAsset($firstState);
                if(!$this->trading->reservePaperBalance(
                    $organizationId,$feeReservation,$opportunityId,$firstState->venueId->value(),$quote,$compFee->value(),$holdUntil->format(DATE_ATOM)
                ))throw new DomainException('INSUFFICIENT_COMPENSATION_FEE_BALANCE');
                $reservations[]=$feeReservation;
            }
        }catch(\Throwable $error){
            foreach(array_slice($reservations,1) as $reservation)$this->safeReleaseBalance($organizationId,$reservation);
            $this->trading->releaseReservation($organizationId,$capitalReservation);
            return $this->invalidated($organizationId,$opportunityId,$hypothesis,$now,$error->getMessage());
        }

        $strategy=(string)($opportunity['strategy_version']??'RelativeValueStrategy-v1');
        $plan=new ExecutionPlan(
            $executionId.':plan',$opportunityId,$strategy,$now,$holdUntil,
            [
                $this->executionLeg($executionId,'1',$first,$firstState,$firstSide,$requested,$simulation['first']['price'],$firstFee),
                $this->executionLeg($executionId,'2',$second,$secondState,$secondSide,$requested,$simulation['second']['price'],$secondFee),
            ],
            'SIMULTANEOUS',PartialFillPolicy::AcceptAndHedgeFilled,CompensationPolicy::EmergencyClose,
            $this->int($options,'max_total_latency_ms',1000),$simulatedLegLatencyMs,
            DecimalMath::add(DecimalMath::add($firstFee,$secondFee),$compFee),
            Decimal::fromString((string)($opportunity['expected_pnl']??'0')),
            (string)($risk['id']??'unknown'),$reservations,ExecutionPolicy::Simultaneous,$maxUnhedgedMs,
            (string)($opportunity['hedge_group_id']??'')
        );
        $this->trading->saveExecutionPlan($organizationId,$plan->id,$opportunityId,$this->plan($plan,'READY'));

        $fills=[];
        $fills[]=$this->persistFill(
            $organizationId,$executionId,'leg1',$first,$firstState,$firstSide,$requested,
            $firstQty,$simulation['first']['price'],$firstFee,$now,$simulation['first']['state']
        );
        if($secondQty->isPositive()){
            $fills[]=$this->persistFill(
                $organizationId,$executionId,'leg2',$second,$secondState,$secondSide,$firstQty,
                $secondQty,$simulation['second']['price'],$secondFee,$now,$simulation['second']['state']
            );
        }
        if($comp!==null&&$comp['filled_quantity']->isPositive()){
            $compSide=$comp['side'];
            $fills[]=$this->persistFill(
                $organizationId,$executionId,'compensation',$first,$firstState,$compSide,$comp['filled_quantity'],
                $comp['filled_quantity'],$comp['price'],$compFee,$now,\Domains\CapitalMarkets\Domain\Execution\PaperOrderState::Filled
            );
        }

        $this->applyCashAndInventory($organizationId,$first,$firstState,$fills,$executionId,'leg1');
        $this->applyCashAndInventory($organizationId,$second,$secondState,$fills,$executionId,'leg2');
        if($comp!==null)$this->consumeFeeReservation($organizationId,$executionId.':comp-fee');

        $positionRows=[];
        $positions=[];
        if($matched->isPositive()){
            [$p1,$row1]=$this->positionForLeg($organizationId,$executionId,$strategy,$first,$firstState,$fills,$matched);
            [$p2,$row2]=$this->positionForLeg($organizationId,$executionId,$strategy,$second,$secondState,$fills,$matched);
            $positions=[$p1,$p2];$positionRows=[$row1,$row2];
        }

        $actualHedge=$this->actualHedge($executionId,$strategy,$first,$second,$matched,$opportunity,$positions);
        $hedgeInspection=$this->hedges->inspect($actualHedge);
        $this->research->saveHedgeGroup($organizationId,$actualHedge,$opportunityId,$executionId);
        if($hedgeInspection['rehedge_required']){
            $this->releaseOnFailure($organizationId,$reservations);
            return $this->invalidated($organizationId,$opportunityId,$hypothesis,$now,'REHEDGE_REQUIRED',[
                'execution_id'=>$executionId,'hedge'=>$hedgeInspection
            ]);
        }

        $ledger=$this->ledger(
            $executionId,$now,$first,$firstState,$second,$secondState,$fills,$matched,$firstFee,$secondFee,$compFee,$options
        );
        $this->trading->saveLedgerTransaction($organizationId,$ledger->id,$ledger->idempotencyKey,[
            'id'=>$ledger->id,'idempotency_key'=>$ledger->idempotencyKey,'posted_at'=>$ledger->postedAt->format(DATE_ATOM),
            'type'=>'RELATIVE_VALUE_POSITION_OPEN',
            'entries'=>array_map(static fn(LedgerEntry $entry):array=>[
                'account'=>$entry->account,'asset_key'=>$entry->assetKey,
                'debit'=>$entry->debit->value(),'credit'=>$entry->credit->value(),
            ],$ledger->entries),
        ]);

        $basisAttribution=Decimal::fromString((string)($opportunity['economics']['expected_basis_pnl_attribution']??'0'));
        $performance=$this->performance->calculate(
            $positions,[],Decimal::fromString('0'),Decimal::fromString('0'),$basisAttribution
        );

        $status=$matched->isPositive()?'OPEN':'COMPLETED_COMPENSATED';
        if(!$matched->isPositive())$this->releaseOnFailure($organizationId,$reservations);
        $payload=[
            'id'=>$executionId,'execution_id'=>$executionId,'opportunity_id'=>$opportunityId,'hypothesis'=>$hypothesis,
            'status'=>$status,'opened_at'=>$now->format(DATE_ATOM),'holding_until'=>$holdUntil->format(DATE_ATOM),
            'strategy_version'=>$strategy,'execution_policy'=>'SIMULTANEOUS','maximum_unhedged_time_ms'=>$maxUnhedgedMs,
            'simulated_leg_latency_ms'=>$simulatedLegLatencyMs,'matched_quantity'=>$matched->value(),
            'partial_fill'=>!$simulation['first']['fully_filled']||!$simulation['second']['fully_filled'],
            'compensated'=>$comp!==null,'residual_unhedged_quantity'=>$simulation['residual_unhedged_quantity']->value(),
            'capital_reservation_id'=>$capitalReservation,'reservation_ids'=>$reservations,
            'hedge_group_id'=>$actualHedge->id,'hedge'=>$hedgeInspection,'positions'=>$positionRows,
            'expected_pnl'=>(string)($opportunity['expected_pnl']??'0'),
            'performance'=>[
                'spot_price_pnl'=>$performance->spotPricePnl->value(),
                'derivative_price_pnl'=>$performance->derivativePricePnl->value(),
                'funding_pnl'=>$performance->fundingPnl->value(),'trading_fees'=>$performance->tradingFees->value(),
                'basis_attribution'=>$performance->basisAttribution->value(),'basis_is_attribution_only'=>true,
                'net_pnl'=>$performance->netPnl->value(),
            ],
        ];
        $this->trading->saveExecution($organizationId,$executionId,$opportunityId,$status,$payload);
        $this->trading->saveExecutionPlan($organizationId,$plan->id,$opportunityId,$this->plan($plan,$status));
        $this->saveObservation($organizationId,$hypothesis,$opportunityId,$executionId,$status,$performance->netPnl,$now);
        return $payload;
    }

    /** @param array<string,mixed> $leg @return array{0:array<string,mixed>,1:MarketState,2:ExecutionSide} */
    private function resolveLeg(string $organizationId,array $leg):array
    {
        $venue=(string)($leg['venue_id']??'');$instrument=(string)($leg['instrument_id']??'');
        if($venue===''||$instrument==='')throw new DomainException('EXECUTION_LEG_IDENTITY_INVALID');
        $state=$this->marketStates->get($organizationId,VenueId::fromString($venue),InstrumentId::fromString($instrument))
            ??throw new DomainException('EXECUTION_MARKET_STATE_UNAVAILABLE');
        $side=match((string)($leg['side']??'')){
            'LONG'=>ExecutionSide::Buy,
            'SHORT'=>ExecutionSide::Sell,
            default=>throw new DomainException('EXECUTION_LEG_SIDE_INVALID'),
        };
        return [$leg,$state,$side];
    }

    private function assertRevalidation(string $hypothesis,MarketState $a,MarketState $b):void
    {
        foreach([$a,$b] as $state){
            if(!$state->quality->status->isUsableForDecision())throw new DomainException('EXECUTION_UNTRUSTED_MARKET_DATA');
            if($state->marketStatus!==MarketStatus::Open)throw new DomainException('EXECUTION_MARKET_NOT_OPEN');
            if($state->bestQuote===null)throw new DomainException('EXECUTION_QUOTE_UNAVAILABLE');
        }
        if(in_array($hypothesis,['H5','H6'],true)){
            foreach([$a,$b] as $state){
                $kind=$state->fundingRate?->eventType();
                if($kind===MarketEventType::FundingRate){
                    $status=(string)($state->fundingRate->attributes['status']??'UNKNOWN');
                    if($status==='UNKNOWN')throw new DomainException('FUNDING_REVALIDATION_FAILED');
                }
            }
            if($hypothesis==='H5'&&$b->fundingRate===null)throw new DomainException('FUNDING_REVALIDATION_FAILED');
            if($hypothesis==='H6'&&($a->fundingRate===null||$b->fundingRate===null))throw new DomainException('FUNDING_REVALIDATION_FAILED');
        }
    }

    /** @param array<string,mixed> $leg @param array<string,mixed> $options @return list<string> */
    private function reserveLegResources(
        string $organizationId,string $opportunityId,string $executionId,string $name,array $leg,MarketState $state,
        ExecutionSide $side,Decimal $notional,Decimal $fee,Decimal $matched,array $options,DateTimeImmutable $holdUntil,
    ):array{
        $quote=$this->quoteAsset($state);$venue=$state->venueId->value();$kind=(string)($leg['instrument_kind']??'PERPETUAL');
        $ids=[];
        if($kind==='SPOT'){
            if($side!==ExecutionSide::Buy)throw new DomainException('PAPER_REVERSE_SPOT_REQUIRES_BORROW_ACCOUNTING');
            $cash=DecimalMath::add($notional,$fee);
            $id=$executionId.':'.$name.':spot-cash';
            if(!$this->trading->reservePaperBalance($organizationId,$id,$opportunityId,$venue,$quote,$cash->value(),$holdUntil->format(DATE_ATOM))){
                throw new DomainException('INSUFFICIENT_SPOT_CASH');
            }
            $ids[]=$id;
            return $ids;
        }

        $leverage=$this->requiredDecimal($options,$name.'_leverage');
        $matchedNotional=DecimalMath::multiply($state->bestQuote?->midPrice()??throw new DomainException('QUOTE_REQUIRED'),$matched);
        $margin=DecimalMath::divide($matchedNotional,$leverage,18);
        $marginId=$executionId.':'.$name.':margin';
        if(!$this->trading->reservePaperBalance($organizationId,$marginId,$opportunityId,$venue,$quote,$margin->value(),$holdUntil->format(DATE_ATOM))){
            throw new DomainException('INSUFFICIENT_DERIVATIVE_MARGIN');
        }
        $ids[]=$marginId;
        if(!$fee->isZero()){
            $feeId=$executionId.':'.$name.':fee';
            if(!$this->trading->reservePaperBalance($organizationId,$feeId,$opportunityId,$venue,$quote,$fee->value(),$holdUntil->format(DATE_ATOM))){
                throw new DomainException('INSUFFICIENT_DERIVATIVE_FEE_BALANCE');
            }
            $ids[]=$feeId;
        }
        return $ids;
    }

    /** @param array<string,mixed> $leg */
    private function executionLeg(
        string $executionId,string $sequence,array $leg,MarketState $state,ExecutionSide $side,
        Decimal $quantity,Decimal $price,Decimal $fee,
    ):ExecutionLeg{
        return new ExecutionLeg(
            $executionId.':leg:'.$sequence,(int)$sequence,$state->key(),$state->instrumentId->value(),$side,$quantity,
            'IOC',null,$price,$fee,Decimal::fromString('0')
        );
    }

    /** @param array<string,mixed> $leg */
    private function persistFill(
        string $organizationId,string $executionId,string $name,array $leg,MarketState $state,ExecutionSide $side,
        Decimal $requested,Decimal $filled,Decimal $price,Decimal $fee,DateTimeImmutable $now,
        \Domains\CapitalMarkets\Domain\Execution\PaperOrderState $orderState,
    ):PaperFill{
        $order=new PaperOrder(
            $executionId.':order:'.$name,$executionId,$executionId.':leg:'.$name,$state->key(),$state->instrumentId->value(),
            $side,$requested,$filled,$orderState,$now,$now,$filled->isPositive()?$now:null
        );
        $this->trading->savePaperOrder(
            $organizationId,$order->id,$executionId,$order->legId,$order->state->value,$order->id,$this->order($order)
        );
        $fill=new PaperFill(
            $executionId.':fill:'.$name,$executionId,$state->venueId->value(),$state->instrumentId->value(),
            $side,$filled,$price,$fee,Decimal::fromString('0'),$now,$executionId.':fill:'.$name
        );
        $this->trading->savePaperFill(
            $organizationId,$fill->id,$order->id,$executionId,$fill->idempotencyKey,$this->fill($fill)
        );
        return $fill;
    }

    /** @param array<string,mixed> $leg @param list<PaperFill> $fills */
    private function applyCashAndInventory(
        string $organizationId,array $leg,MarketState $state,array $fills,string $executionId,string $name
    ):void{
        $kind=(string)($leg['instrument_kind']??'PERPETUAL');
        if($kind==='SPOT'){
            $reservation=$executionId.':'.$name.':spot-cash';
            $this->trading->consumePaperBalanceReservation($organizationId,$reservation);
            $base=$state->bestQuote?->askPrice->baseAsset->value()??$state->instrumentId->value();
            $quote=$this->quoteAsset($state);
            foreach($fills as $fill){
                if($fill->venueId!==$state->venueId->value()||$fill->instrumentId!==$state->instrumentId->value())continue;
                if($fill->side===ExecutionSide::Buy){
                    $this->trading->creditPaperBalance($organizationId,$fill->venueId,$base,$fill->quantity->value());
                }else{
                    $this->trading->adjustPaperBalance($organizationId,$fill->venueId,$base,DecimalMath::negate($fill->quantity)->value());
                    $cash=DecimalMath::subtract($fill->notional(),$fill->fee);
                    $this->trading->creditPaperBalance($organizationId,$fill->venueId,$quote,$cash->value());
                }
            }
            return;
        }
        $this->consumeFeeReservation($organizationId,$executionId.':'.$name.':fee');
    }

    private function consumeFeeReservation(string $organizationId,string $id):void
    {
        try{$this->trading->consumePaperBalanceReservation($organizationId,$id);}catch(\Throwable){}
    }

    /** @param array<string,mixed> $leg @param list<PaperFill> $fills @return array{0:Position,1:array<string,mixed>} */
    private function positionForLeg(
        string $organizationId,string $executionId,string $strategy,array $leg,MarketState $state,array $fills,Decimal $matched
    ):array{
        $side=(string)($leg['side']??'')==='LONG'?PositionSide::Long:PositionSide::Short;
        $kind=(string)($leg['instrument_kind']??'PERPETUAL');
        $relevant=[];
        foreach($fills as $fill){
            if($fill->venueId===$state->venueId->value()&&$fill->instrumentId===$state->instrumentId->value())$relevant[]=$fill;
        }
        $position=$this->positions->projectDirectional(
            'paper:'.$executionId,$strategy,$state->instrumentId->value(),$state->venueId->value(),$relevant,
            $state->markPrice?->value??$state->bestQuote?->midPrice()??throw new DomainException('MARK_PRICE_UNAVAILABLE'),
            $side,$kind==='SPOT'?Decimal::fromString('1'):Decimal::fromString('1')
        );
        if($position->quantity->compareTo($matched)!==0)throw new DomainException('POSITION_QUANTITY_DOES_NOT_MATCH_HEDGE');
        $row=[
            'position_id'=>$position->positionId,'portfolio_id'=>$position->portfolioId,'strategy_id'=>$position->strategyId,
            'instrument_id'=>$position->instrumentId,'venue_id'=>$position->venueId,'status'=>$position->status(),
            'side'=>$position->side->value,'quantity'=>$position->quantity->value(),
            'average_entry_price'=>$position->averageEntryPrice->value(),'mark_price'=>$position->markPrice->value(),
            'market_value'=>$position->marketValue()->value(),'fees'=>$position->fees->value(),
            'realized_pnl'=>$position->realizedPnl->value(),'unrealized_pnl'=>$position->unrealizedPnl()->value(),
            'contract_multiplier'=>$kind==='SPOT'?null:'1','instrument_kind'=>$kind,
            'opened_at'=>$position->openedAt?->format(DATE_ATOM),'updated_at'=>$position->updatedAt?->format(DATE_ATOM),
            'closed_at'=>$position->closedAt?->format(DATE_ATOM),
        ];
        $this->trading->savePosition(
            $organizationId,(string)$position->positionId,'paper:'.$executionId,$strategy,
            $position->instrumentId,$position->venueId,$position->status(),$row
        );
        return [$position,$row];
    }

    /** @param array<string,mixed> $first @param array<string,mixed> $second @param list<Position> $positions */
    private function actualHedge(
        string $executionId,string $strategy,array $first,array $second,Decimal $matched,array $opportunity,array $positions
    ):HedgeGroup{
        $legs=[];
        foreach($positions as $position){
            $legs[]=new HedgeLeg(
                $position->instrumentId,$position->venueId,$position->side,$position->quantity,
                Decimal::fromString('1'),$position->contractMultiplier??Decimal::fromString('1')
            );
        }
        if(count($legs)<2){
            $legs=[
                new HedgeLeg((string)$first['instrument_id'],(string)$first['venue_id'],PositionSide::Long,Decimal::fromString('0.000000000001'),Decimal::fromString('1'),Decimal::fromString('1')),
                new HedgeLeg((string)$second['instrument_id'],(string)$second['venue_id'],PositionSide::Short,Decimal::fromString('0.000000000001'),Decimal::fromString('1'),Decimal::fromString('1')),
            ];
        }
        return new HedgeGroup(
            (string)($opportunity['hedge_group_id']??('cm_hg_'.substr(hash('sha256',$executionId),0,40))),
            $strategy,$legs,Decimal::fromString('0'),Decimal::fromString('0.000001'),
            $matched->isPositive()?HedgeState::Hedged:HedgeState::Closed
        );
    }

    /** @param array<string,mixed> $first @param array<string,mixed> $second @param list<PaperFill> $fills */
    private function ledger(
        string $executionId,DateTimeImmutable $at,array $first,MarketState $firstState,array $second,MarketState $secondState,
        array $fills,Decimal $matched,Decimal $firstFee,Decimal $secondFee,Decimal $compFee,array $options,
    ):LedgerTransaction{
        $entries=[];
        foreach([[$first,$firstState],[$second,$secondState]] as [$leg,$state]){
            $kind=(string)($leg['instrument_kind']??'PERPETUAL');
            $quote=$this->quoteAsset($state);
            if($kind==='SPOT'){
                foreach($fills as $fill){
                    if($fill->venueId!==$state->venueId->value()||$fill->instrumentId!==$state->instrumentId->value())continue;
                    $base=$state->bestQuote?->askPrice->baseAsset->value()??$state->instrumentId->value();
                    if($fill->side===ExecutionSide::Buy){
                        $entries[] = new LedgerEntry('venue:'.$fill->venueId.':inventory',$fill->quantity,Decimal::fromString('0'),$base);
                        $entries[] = new LedgerEntry('external:'.$fill->venueId.':inventory',Decimal::fromString('0'),$fill->quantity,$base);
                        $entries[] = new LedgerEntry('external:'.$fill->venueId.':cash',$fill->notional(),Decimal::fromString('0'),$quote);
                        $entries[] = new LedgerEntry('venue:'.$fill->venueId.':cash',Decimal::fromString('0'),$fill->notional(),$quote);
                    }else{
                        $entries[] = new LedgerEntry('external:'.$fill->venueId.':inventory',$fill->quantity,Decimal::fromString('0'),$base);
                        $entries[] = new LedgerEntry('venue:'.$fill->venueId.':inventory',Decimal::fromString('0'),$fill->quantity,$base);
                        $entries[] = new LedgerEntry('venue:'.$fill->venueId.':cash',$fill->notional(),Decimal::fromString('0'),$quote);
                        $entries[] = new LedgerEntry('external:'.$fill->venueId.':cash',Decimal::fromString('0'),$fill->notional(),$quote);
                    }
                    if(!$fill->fee->isZero()){
                        $entries[] = new LedgerEntry('trading_fee:'.$fill->venueId,$fill->fee,Decimal::fromString('0'),$quote);
                        $entries[] = new LedgerEntry('venue:'.$fill->venueId.':cash',Decimal::fromString('0'),$fill->fee,$quote);
                    }
                }
                continue;
            }

            if($matched->isPositive()){
                $memoAsset='POSITION:'.$state->instrumentId->value();
                $side=(string)($leg['side']??'');
                if($side==='LONG'){
                    $entries[]=new LedgerEntry('perp_position_long:'.$state->venueId->value(),$matched,Decimal::fromString('0'),$memoAsset);
                    $entries[]=new LedgerEntry('external:perp_position',Decimal::fromString('0'),$matched,$memoAsset);
                }else{
                    $entries[]=new LedgerEntry('external:perp_position',$matched,Decimal::fromString('0'),$memoAsset);
                    $entries[]=new LedgerEntry('perp_position_short:'.$state->venueId->value(),Decimal::fromString('0'),$matched,$memoAsset);
                }
                $levKey=$state->instrumentId->value()===(string)($first['instrument_id']??'')?'leg1_leverage':'leg2_leverage';
                $leverage=$this->requiredDecimal($options,$levKey);
                $margin=DecimalMath::divide(DecimalMath::multiply($state->bestQuote->midPrice(),$matched),$leverage,18);
                $entries[]=new LedgerEntry('venue:'.$state->venueId->value().':margin_collateral',$margin,Decimal::fromString('0'),$quote);
                $entries[]=new LedgerEntry('venue:'.$state->venueId->value().':cash',Decimal::fromString('0'),$margin,$quote);
            }
        }
        foreach($fills as $fill){
            $kind=$fill->instrumentId===(string)($first['instrument_id']??'')?(string)($first['instrument_kind']??'PERPETUAL'):(string)($second['instrument_kind']??'PERPETUAL');
            if($kind==='SPOT'||$fill->fee->isZero())continue;
            $state=$fill->instrumentId===$firstState->instrumentId->value()?$firstState:$secondState;
            $quote=$this->quoteAsset($state);
            $entries[]=new LedgerEntry('trading_fee:'.$fill->venueId,$fill->fee,Decimal::fromString('0'),$quote);
            $entries[]=new LedgerEntry('venue:'.$fill->venueId.':cash',Decimal::fromString('0'),$fill->fee,$quote);
        }
        if($entries===[])throw new DomainException('LEDGER_ENTRIES_REQUIRED');
        return new LedgerTransaction(
            'cm_ledger_'.substr(hash('sha256',$executionId.'|open'),0,40),$executionId.':open',$at,$entries
        );
    }

    /** @return list<string> */
    private function releaseOnFailure(string $organizationId,array $reservations):array
    {
        foreach(array_slice($reservations,1) as $reservation)$this->safeReleaseBalance($organizationId,(string)$reservation);
        if(isset($reservations[0]))try{$this->trading->releaseReservation($organizationId,(string)$reservations[0]);}catch(\Throwable){}
        return [];
    }
    private function safeReleaseBalance(string $organizationId,string $reservation):void
    {
        try{$this->trading->releasePaperBalanceReservation($organizationId,$reservation);}catch(\Throwable){}
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function invalidated(
        string $organizationId,string $opportunityId,string $hypothesis,DateTimeImmutable $at,string $reason,array $evidence=[]
    ):array{
        $executionId=(string)($evidence['execution_id']??('cm_exec_'.bin2hex(random_bytes(12))));
        $payload=[
            'id'=>$executionId,'execution_id'=>$executionId,'opportunity_id'=>$opportunityId,
            'hypothesis'=>$hypothesis,'status'=>'INVALIDATED','executed_at'=>$at->format(DATE_ATOM),
            'reason'=>$reason,'realized_pnl'=>'0','evidence'=>$evidence,
        ];
        $this->trading->saveExecution($organizationId,$executionId,$opportunityId,'INVALIDATED',$payload);
        $this->saveObservation($organizationId,$hypothesis,$opportunityId,$executionId,'INVALIDATED',Decimal::fromString('0'),$at,$reason);
        return $payload;
    }

    private function saveObservation(
        string $organizationId,string $hypothesis,string $opportunityId,string $executionId,string $status,
        Decimal $realizedPnl,DateTimeImmutable $at,?string $reason=null,
    ):void{
        $fingerprint=hash('sha256',implode('|',[$organizationId,$hypothesis,'EXECUTION',$opportunityId,$executionId]));
        $this->trading->saveHypothesisObservation(
            $organizationId,'cm_obs_'.substr($fingerprint,0,40),$hypothesis,'EXECUTION',$at->format(DATE_ATOM),$fingerprint,
            ['opportunity_id'=>$opportunityId,'execution_id'=>$executionId,'detected'=>true,
             'executable'=>$status==='OPEN','realized'=>$status!=='INVALIDATED',
             'expected_pnl'=>'0','realized_pnl'=>$realizedPnl->value(),'reason'=>$reason]
        );
    }

    private function quoteAsset(MarketState $state):string
    {
        return $state->bestQuote?->askPrice->quoteAsset->value()??throw new DomainException('QUOTE_ASSET_UNAVAILABLE');
    }

    /** @return array<string,mixed> */
    private function plan(ExecutionPlan $plan,string $status):array
    {
        return [
            'id'=>$plan->id,'opportunity_id'=>$plan->opportunityId,'strategy_version'=>$plan->strategyVersion,
            'status'=>$status,'created_at'=>$plan->createdAt->format(DATE_ATOM),'expires_at'=>$plan->expiresAt->format(DATE_ATOM),
            'sequence_policy'=>$plan->sequencePolicy,'execution_policy'=>$plan->executionPolicy->value,
            'partial_fill_policy'=>$plan->partialFillPolicy->value,'compensation_policy'=>$plan->compensationPolicy->value,
            'max_total_latency_ms'=>$plan->maxTotalLatencyMs,'max_leg_latency_ms'=>$plan->maxLegLatencyMs,
            'maximum_unhedged_time_ms'=>$plan->maximumUnhedgedTimeMs,'hedge_group_id'=>$plan->hedgeGroupId,
            'expected_cost'=>$plan->expectedCost->value(),'expected_pnl'=>$plan->expectedPnl->value(),
            'risk_assessment_id'=>$plan->riskAssessmentId,'capital_reservation_ids'=>$plan->capitalReservationIds,
        ];
    }
    private function order(PaperOrder $order):array{return [
        'id'=>$order->id,'execution_group_id'=>$order->executionGroupId,'leg_id'=>$order->legId,
        'venue_market_id'=>$order->venueMarketId,'instrument_id'=>$order->instrumentId,'side'=>$order->side->value,
        'requested_quantity'=>$order->requestedQuantity->value(),'filled_quantity'=>$order->filledQuantity->value(),
        'remaining_quantity'=>$order->remainingQuantity()->value(),'state'=>$order->state->value,
        'created_at'=>$order->createdAt->format(DATE_ATOM),'submitted_at'=>$order->submittedAt?->format(DATE_ATOM),
        'filled_at'=>$order->filledAt?->format(DATE_ATOM),
    ];}
    private function fill(PaperFill $fill):array{return [
        'id'=>$fill->id,'venue_id'=>$fill->venueId,'instrument_id'=>$fill->instrumentId,'side'=>$fill->side->value,
        'quantity'=>$fill->quantity->value(),'price'=>$fill->price->value(),'notional'=>$fill->notional()->value(),
        'fee'=>$fill->fee->value(),'slippage'=>$fill->slippage->value(),'filled_at'=>$fill->filledAt->format(DATE_ATOM),
        'idempotency_key'=>$fill->idempotencyKey,
    ];}

    private function requiredDecimal(array $options,string $key):Decimal
    {
        if(!array_key_exists($key,$options))throw new InvalidArgumentException($key.' is required.');
        $value=Decimal::fromString((string)$options[$key]);
        if($value->isNegative())throw new InvalidArgumentException($key.' cannot be negative.');
        return $value;
    }
    private function requiredInt(array $options,string $key):int
    {
        if(!array_key_exists($key,$options))throw new InvalidArgumentException($key.' is required.');
        $value=(int)$options[$key];if($value<0)throw new InvalidArgumentException($key.' cannot be negative.');return $value;
    }
    private function int(array $options,string $key,int $default):int{return array_key_exists($key,$options)?(int)$options[$key]:$default;}
}
