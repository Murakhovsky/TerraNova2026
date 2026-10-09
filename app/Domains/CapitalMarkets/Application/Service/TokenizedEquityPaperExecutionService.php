<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\TokenizedEquityVerticalSliceRepositoryInterface;
use Domains\CapitalMarkets\Domain\Execution\ExecutionPerformance;
use Domains\CapitalMarkets\Domain\Execution\ExecutionLeg;
use Domains\CapitalMarkets\Domain\Execution\ExecutionPlan;
use Domains\CapitalMarkets\Domain\Execution\PaperOrder;
use Domains\CapitalMarkets\Domain\Execution\PaperOrderState;
use Domains\CapitalMarkets\Domain\Execution\PartialFillPolicy;
use Domains\CapitalMarkets\Domain\Execution\CompensationPolicy;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperFill;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Ledger\LedgerEntry;
use Domains\CapitalMarkets\Domain\Ledger\LedgerTransaction;
use Domains\CapitalMarkets\Domain\Observability\CapitalMarketsAlertType;
use Domains\CapitalMarkets\Domain\Service\ExecutablePriceCalculator;
use Domains\CapitalMarkets\Domain\Service\PaperPnlEngine;
use Domains\CapitalMarkets\Domain\Service\PositionProjector;
use Domains\CapitalMarkets\Domain\Service\PaperMultiLegExecutionSimulator;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

final readonly class TokenizedEquityPaperExecutionService
{
    public function __construct(
        private MarketStateRepositoryInterface $marketStates,
        private TokenizedEquityVerticalSliceRepositoryInterface $repository,
        private ExecutablePriceCalculator $prices,
        private PaperPnlEngine $pnl,
        private PaperMultiLegExecutionSimulator $multiLeg,
        private PositionProjector $positions,
        private TokenizedEquityTelemetry $telemetry,
    ){}

    /** @return array<string,mixed> */
    public function initializePortfolio(string $organizationId,string $currency,string $initialCapital):array
    {
        $capital=Decimal::fromString($initialCapital);
        if(!$capital->isPositive())throw new DomainException('Paper initial capital must be positive.');
        return $this->repository->initializePaperPortfolio($organizationId,$currency,$capital->value());
    }

    /** @return array<string,mixed>|null */
    public function portfolio(string $organizationId):?array
    {
        $portfolio=$this->repository->paperPortfolio($organizationId);
        if($portfolio!==null)$portfolio['venue_balances']=$this->repository->listPaperBalances($organizationId);
        return $portfolio;
    }

    public function setVenueBalance(
        string $organizationId,string $venueId,string $assetKey,string $amount
    ):void{
        $value=Decimal::fromString($amount);
        if($value->isNegative())throw new DomainException('Paper venue balance cannot be negative.');
        $this->repository->setPaperBalance($organizationId,$venueId,$assetKey,$value->value());
    }

    /** @return array<string,mixed> */
    public function execute(string $organizationId,string $opportunityId):array
    {
        $this->telemetry->metric($organizationId,'paper_execution_total',1.0,['phase'=>'attempt']);
        $opportunity=$this->repository->getOpportunity($organizationId,$opportunityId);
        if($opportunity===null)throw new DomainException('Opportunity not found.');
        $existingExecution=$this->repository->getExecutionForOpportunity($organizationId,$opportunityId);
        if($existingExecution!==null){
            $existingStatus=(string)($existingExecution['status']??'');
            if(in_array($existingStatus,['COMPLETED','COMPLETED_COMPENSATED','INVALIDATED','FAILED','CANCELLED','EXPIRED'],true))return $existingExecution;
            throw new DomainException('EXECUTION_RECOVERY_REQUIRED');
        }
        $hypothesis=(string)($opportunity['hypothesis']??'');
        if(!in_array($hypothesis,['H1','H2'],true))throw new DomainException('Unsupported paper execution hypothesis.');
        if(($opportunity['status']??null)!=='APPROVED')throw new DomainException('Opportunity is not approved for paper execution.');

        $now=new DateTimeImmutable();
        $expires=new DateTimeImmutable((string)$opportunity['expires_at']);
        if($now >= $expires)throw new DomainException('OPPORTUNITY_EXPIRED');

        $candidate=$opportunity['candidate']??null;
        $parameters=$opportunity['execution_parameters']??null;
        $risk=$opportunity['risk']??null;
        if(!is_array($candidate)||!is_array($parameters)||!is_array($risk))throw new DomainException('Opportunity execution evidence is incomplete.');

        $quantity=Decimal::fromString((string)($risk['approved_quantity']??$parameters['quantity']??'0'));
        if(!$quantity->isPositive())throw new DomainException('No risk-approved execution quantity.');

        $buyVenue=(string)($candidate['buy_venue_id']??'');
        $sellVenue=(string)($candidate['sell_venue_id']??'');
        $buyInstrument=(string)($candidate['buy_instrument_id']??'');
        $sellInstrument=(string)($candidate['sell_instrument_id']??'');
        if($buyVenue===''||$sellVenue===''||$buyInstrument===''||$sellInstrument==='')throw new DomainException('Opportunity legs are incomplete.');
        if($hypothesis==='H1'&&(str_starts_with($buyVenue,'reference:')||str_starts_with($sellVenue,'reference:'))){
            throw new DomainException('H1 paper execution requires a real executable hedge venue.');
        }

        $executionId='cm_exec_'.bin2hex(random_bytes(12));
        $executionEvidence=[
            'market_pair_id'=>(string)($candidate['market_pair_id']??''),
            'candidate_id'=>(string)($candidate['id']??''),
            'expected_pnl'=>(string)($opportunity['expected_pnl']??'0'),
            'hypothesis'=>$hypothesis,
        ];

        $buyState=$this->marketStates->get($organizationId,VenueId::fromString($buyVenue),InstrumentId::fromString($buyInstrument));
        $sellState=$this->marketStates->get($organizationId,VenueId::fromString($sellVenue),InstrumentId::fromString($sellInstrument));
        if($buyState===null||$sellState===null){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'EXECUTION_MARKET_STATE_UNAVAILABLE',$executionEvidence);
        }
        if(!$buyState->quality->status->isUsableForDecision()||!$sellState->quality->status->isUsableForDecision()){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'EXECUTION_UNTRUSTED_MARKET_DATA',$executionEvidence);
        }
        if($buyState->bestQuote===null||$sellState->bestQuote===null){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'EXECUTION_QUOTE_UNAVAILABLE',$executionEvidence);
        }
        if(!$buyState->bestQuote->askPrice->quoteAsset->equals($sellState->bestQuote->bidPrice->quoteAsset)){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'EXECUTION_QUOTE_CURRENCY_NOT_NORMALIZED',$executionEvidence);
        }

        if($buyState->orderBook!==null&&$sellState->orderBook!==null){
            return $this->executeOrderBookPath(
                $organizationId,$opportunityId,$executionId,$opportunity,$candidate,$parameters,$risk,
                $buyState,$sellState,$quantity,$now,$expires,$executionEvidence
            );
        }

        try{
            $buy=$this->executable($buyState,ExecutionSide::Buy,$quantity);
            $sell=$this->executable($sellState,ExecutionSide::Sell,$quantity);
        }catch(DomainException $error){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,$error->getMessage(),$executionEvidence);
        }
        if($sell['price']->compareTo($buy['price'])<=0){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'OPPORTUNITY_INVALIDATED',[
                ...$executionEvidence,'buy_price'=>$buy['price']->value(),'sell_price'=>$sell['price']->value(),
            ]);
        }

        $buyFeeRate=Decimal::fromString((string)($parameters['buy_fee_rate']??''));
        $sellFeeRate=Decimal::fromString((string)($parameters['sell_fee_rate']??''));
        $buyFee=DecimalMath::multiply($buy['notional'],$buyFeeRate);
        $sellFee=DecimalMath::multiply($sell['notional'],$sellFeeRate);

        $detectedBuy=Decimal::fromString((string)$candidate['buy_price']);
        $detectedSell=Decimal::fromString((string)$candidate['sell_price']);
        $buySlipUnit=DecimalMath::subtract($buy['price'],$detectedBuy);
        if($buySlipUnit->isNegative())$buySlipUnit=Decimal::fromString('0');
        $sellSlipUnit=DecimalMath::subtract($detectedSell,$sell['price']);
        if($sellSlipUnit->isNegative())$sellSlipUnit=Decimal::fromString('0');
        $buySlippage=DecimalMath::multiply($buySlipUnit,$quantity);
        $sellSlippage=DecimalMath::multiply($sellSlipUnit,$quantity);

        $preflightNet=DecimalMath::subtract(
            DecimalMath::subtract($sell['notional'],$buy['notional']),
            DecimalMath::add($buyFee,$sellFee)
        );
        if(!$preflightNet->isPositive()){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'OPPORTUNITY_INVALIDATED_AFTER_EXECUTABLE_PRICING',[
                ...$executionEvidence,'preflight_net'=>$preflightNet->value(),
                'buy_fee'=>$buyFee->value(),'sell_fee'=>$sellFee->value(),
            ]);
        }
        $reservationId='cm_res_'.substr(hash('sha256',$opportunityId.'|'.$executionId),0,40);
        $buyCashReservation=$reservationId.':buy-cash';
        $sellInventoryReservation=$reservationId.':sell-inventory';
        $requiredCapital=DecimalMath::add($buy['notional'],$buyFee);
        $quoteAsset=$buyState->bestQuote->askPrice->quoteAsset->value();

        if(!$this->repository->reserveCapital(
            $organizationId,$reservationId,$opportunityId,$requiredCapital->value(),$expires->format(DATE_ATOM)
        )){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'INSUFFICIENT_PAPER_CAPITAL',$executionEvidence);
        }

        if(!$this->repository->reservePaperBalance(
            $organizationId,$buyCashReservation,$opportunityId,$buyVenue,$quoteAsset,
            $requiredCapital->value(),$expires->format(DATE_ATOM)
        )){
            $this->repository->releaseReservation($organizationId,$reservationId);
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'INSUFFICIENT_PREFUNDED_BUY_CASH',$executionEvidence);
        }

        if(!$this->repository->reservePaperBalance(
            $organizationId,$sellInventoryReservation,$opportunityId,$sellVenue,$sellInstrument,
            $quantity->value(),$expires->format(DATE_ATOM)
        )){
            $this->repository->releasePaperBalanceReservation($organizationId,$buyCashReservation);
            $this->repository->releaseReservation($organizationId,$reservationId);
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'INSUFFICIENT_PREFUNDED_INVENTORY',$executionEvidence);
        }

        $buyLeg=new ExecutionLeg(
            $executionId.':leg:buy',1,$buyVenue.':'.$buyInstrument,$buyInstrument,ExecutionSide::Buy,$quantity,
            'IOC',null,$buy['price'],$buyFee,$buySlippage
        );
        $sellLeg=new ExecutionLeg(
            $executionId.':leg:sell',2,$sellVenue.':'.$sellInstrument,$sellInstrument,ExecutionSide::Sell,$quantity,
            'IOC',null,$sell['price'],$sellFee,$sellSlippage
        );
        $plan=new ExecutionPlan(
            $executionId.':plan',$opportunityId,'TokenizedEquityRelativeValue-v1',$now,$expires,[$buyLeg,$sellLeg],
            'SEQUENTIAL',PartialFillPolicy::AbortAndCompensate,CompensationPolicy::EmergencyClose,
            (int)($parameters['max_total_latency_ms']??1000),(int)($parameters['max_leg_latency_ms']??500),
            DecimalMath::add($buyFee,$sellFee),Decimal::fromString((string)$opportunity['expected_pnl']),
            (string)($risk['id']??'unknown'),[$reservationId,$buyCashReservation,$sellInventoryReservation]
        );
        $this->repository->saveExecutionPlan($organizationId,$plan->id,$opportunityId,$this->planArray($plan,'READY'));
        $checkpoint=[
            'id'=>$executionId,'opportunity_id'=>$opportunityId,'status'=>'READY','checkpoint'=>'RESERVATIONS_READY',
            'executed_at'=>$now->format(DATE_ATOM),'reservation_id'=>$reservationId,
            'buy_cash_reservation_id'=>$buyCashReservation,'sell_inventory_reservation_id'=>$sellInventoryReservation,
            'buy_venue_id'=>$buyVenue,'sell_venue_id'=>$sellVenue,
            'buy_instrument_id'=>$buyInstrument,'sell_instrument_id'=>$sellInstrument,'quote_asset'=>$quoteAsset,
            'quantity'=>$quantity->value(),'plan_id'=>$plan->id,'realized_pnl'=>'0','edge_capture_ratio'=>'0',
        ];
        $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'READY',$checkpoint);

        $firstLegPersisted=false;
        try{
            $buyOrder=new PaperOrder(
                $executionId.':order:buy',$executionId,$buyLeg->id,$buyLeg->venueMarketId,$buyInstrument,ExecutionSide::Buy,
                $quantity,$quantity,PaperOrderState::Filled,$now,$now,$now
            );
            $this->repository->savePaperOrder(
                $organizationId,$buyOrder->id,$executionId,$buyLeg->id,$buyOrder->state->value,$buyOrder->id,$this->orderArray($buyOrder)
            );
            $buyFill=new PaperFill(
                $executionId.':buy',$executionId,$buyVenue,$buyInstrument,ExecutionSide::Buy,$quantity,$buy['price'],$buyFee,
                $buySlippage,$now,$executionId.':buy'
            );
            $this->repository->savePaperFill(
                $organizationId,$buyFill->id,$buyOrder->id,$executionId,$buyFill->idempotencyKey,$this->fillArray($buyFill)
            );
            $firstLegPersisted=true;
            $checkpoint=[
                ...$checkpoint,'status'=>'PARTIALLY_EXECUTED','checkpoint'=>'LEG1_FILLED',
                'buy_order'=>$this->orderArray($buyOrder),'buy_fill'=>$this->fillArray($buyFill),
            ];
            $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'PARTIALLY_EXECUTED',$checkpoint);

            $sellOrder=new PaperOrder(
                $executionId.':order:sell',$executionId,$sellLeg->id,$sellLeg->venueMarketId,$sellInstrument,ExecutionSide::Sell,
                $quantity,$quantity,PaperOrderState::Filled,$now,$now,$now
            );
            $this->repository->savePaperOrder(
                $organizationId,$sellOrder->id,$executionId,$sellLeg->id,$sellOrder->state->value,$sellOrder->id,$this->orderArray($sellOrder)
            );
            $sellFill=new PaperFill(
                $executionId.':sell',$executionId,$sellVenue,$sellInstrument,ExecutionSide::Sell,$quantity,$sell['price'],$sellFee,
                $sellSlippage,$now,$executionId.':sell'
            );
            $this->repository->savePaperFill(
                $organizationId,$sellFill->id,$sellOrder->id,$executionId,$sellFill->idempotencyKey,$this->fillArray($sellFill)
            );
            $checkpoint=[
                ...$checkpoint,'status'=>'EXECUTING','checkpoint'=>'LEGS_MATCHED',
                'sell_order'=>$this->orderArray($sellOrder),'sell_fill'=>$this->fillArray($sellFill),
            ];
            $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'EXECUTING',$checkpoint);

            $realized=$this->pnl->realized([$buyFill,$sellFill]);

            $detectedEdge=Decimal::fromString((string)$candidate['gross_spread']);
            $executableEdge=DecimalMath::subtract($sell['price'],$buy['price']);
            $realizedEdge=DecimalMath::divide($realized,$quantity,12);
            $performance=new ExecutionPerformance(
                $executionId,$detectedEdge,$executableEdge,$realizedEdge,
                Decimal::fromString((string)$opportunity['expected_pnl']),$realized,$requiredCapital,0
            );

            $ledger=new LedgerTransaction(
                'cm_ledger_'.bin2hex(random_bytes(12)),$executionId.':settlement',$now,[
                    // Token acquired on buy venue.
                    new LedgerEntry('venue:'.$buyVenue.':inventory', $quantity, Decimal::fromString('0'), $buyInstrument),
                    new LedgerEntry('external:'.$buyVenue.':inventory', Decimal::fromString('0'), $quantity, $buyInstrument),

                    // Token delivered from pre-funded sell venue.
                    new LedgerEntry('external:'.$sellVenue.':inventory', $quantity, Decimal::fromString('0'), $sellInstrument),
                    new LedgerEntry('venue:'.$sellVenue.':inventory', Decimal::fromString('0'), $quantity, $sellInstrument),

                    // Quote currency settlement and fees.
                    new LedgerEntry('external:'.$buyVenue.':cash', $buy['notional'], Decimal::fromString('0'), $quoteAsset),
                    new LedgerEntry('venue:'.$buyVenue.':cash', Decimal::fromString('0'), $buy['notional'], $quoteAsset),
                    new LedgerEntry('fees:'.$buyVenue, $buyFee, Decimal::fromString('0'), $quoteAsset),
                    new LedgerEntry('venue:'.$buyVenue.':cash', Decimal::fromString('0'), $buyFee, $quoteAsset),

                    new LedgerEntry('venue:'.$sellVenue.':cash', $sell['notional'], Decimal::fromString('0'), $quoteAsset),
                    new LedgerEntry('external:'.$sellVenue.':cash', Decimal::fromString('0'), $sell['notional'], $quoteAsset),
                    new LedgerEntry('fees:'.$sellVenue, $sellFee, Decimal::fromString('0'), $quoteAsset),
                    new LedgerEntry('venue:'.$sellVenue.':cash', Decimal::fromString('0'), $sellFee, $quoteAsset),
                ]
            );

            $payload=[
                'id'=>$executionId,'opportunity_id'=>$opportunityId,'status'=>'COMPLETED',
                'executed_at'=>$now->format(DATE_ATOM),'quote_asset'=>$quoteAsset,'reservation_id'=>$reservationId,
                'quantity'=>$quantity->value(),'buy_fill'=>$this->fillArray($buyFill),'sell_fill'=>$this->fillArray($sellFill),
                'detected_edge'=>$detectedEdge->value(),'executable_edge'=>$executableEdge->value(),
                'realized_edge'=>$realizedEdge->value(),'expected_pnl'=>$opportunity['expected_pnl'],
                'realized_pnl'=>$realized->value(),'edge_capture_ratio'=>$performance->edgeCaptureRatio->value(),
                'slippage'=>[
                    'buy'=>$buySlippage->value(),'sell'=>$sellSlippage->value(),
                    'total'=>DecimalMath::add($buySlippage,$sellSlippage)->value(),
                ],
                'fees'=>['buy'=>$buyFee->value(),'sell'=>$sellFee->value()],
            ];
            $this->repository->saveLedgerTransaction($organizationId,$ledger->id,$ledger->idempotencyKey,[
                'id'=>$ledger->id,'idempotency_key'=>$ledger->idempotencyKey,'posted_at'=>$ledger->postedAt->format(DATE_ATOM),
                'entries'=>array_map(static fn(LedgerEntry $e):array=>[
                    'account'=>$e->account,'asset_key'=>$e->assetKey,'debit'=>$e->debit->value(),'credit'=>$e->credit->value(),
                ],$ledger->entries),
            ]);
            $checkpoint=[
                ...$checkpoint,'status'=>'EXECUTING','checkpoint'=>'LEDGER_POSTED',
                'realized_pnl'=>$realized->value(),'edge_capture_ratio'=>$performance->edgeCaptureRatio->value(),
            ];
            $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'EXECUTING',$checkpoint);
            $this->repository->saveExecutionPlan($organizationId,$plan->id,$opportunityId,$this->planArray($plan,'ACCOUNTING'));
            // Settlement is one atomic, idempotent financial transition. A crash can happen
            // before or after this call without duplicating inventory, cash or realized P&L.
            $sellCash=DecimalMath::subtract($sell['notional'],$sellFee);
            $this->repository->settlePaperExecution(
                $organizationId,$executionId,$reservationId,$buyCashReservation,$sellInventoryReservation,
                $buyVenue,$buyInstrument,$quantity->value(),$sellVenue,$quoteAsset,$sellCash->value(),$realized->value()
            );
            $this->persistBuyVenuePosition(
                $organizationId,$executionId,$buyVenue,$buyInstrument,[$buyFill],$buy['price']
            );
            $this->repository->saveExecutionPlan($organizationId,$plan->id,$opportunityId,$this->planArray($plan,'COMPLETED'));
            $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'COMPLETED',$payload);
            $observationFingerprint=hash('sha256',implode('|',[
                $organizationId,$hypothesis,'EXECUTION',$opportunityId,$executionId,
            ]));
            $this->repository->saveHypothesisObservation(
                $organizationId,
                'cm_obs_'.substr($observationFingerprint,0,40),
                $hypothesis,
                'EXECUTION',
                $now->format(DATE_ATOM),
                $observationFingerprint,
                [
                    'market_pair_id'=>(string)($candidate['market_pair_id']??''),
                    'candidate_id'=>(string)($candidate['id']??''),
                    'opportunity_id'=>$opportunityId,
                    'execution_id'=>$executionId,
                    'detected'=>true,
                    'executable'=>true,
                    'realized'=>true,
                    'expected_pnl'=>(string)$opportunity['expected_pnl'],
                    'realized_pnl'=>$realized->value(),
                    'reason'=>null,
                    'edge_capture_ratio'=>$performance->edgeCaptureRatio->value(),
                ]
            );

            $this->recordExecutionTelemetry(
                $organizationId,(string)($payload['status']??'COMPLETED'),
                Decimal::fromString((string)($payload['realized_pnl']??'0')),
                Decimal::fromString((string)($payload['edge_capture_ratio']??'0'))
            );
            return $payload;
        }catch(\Throwable $error){
            if($firstLegPersisted){
                $failedCheckpoint=[
                    ...$checkpoint,
                    'status'=>'COMPENSATING',
                    'checkpoint'=>'RECOVERY_REQUIRED',
                    'failure_reason'=>$error->getMessage(),
                    'realized_pnl'=>'0',
                    'edge_capture_ratio'=>'0',
                ];
                $this->repository->saveExecution(
                    $organizationId,$executionId,$opportunityId,'COMPENSATING',$failedCheckpoint
                );
                throw new DomainException('EXECUTION_RECOVERY_REQUIRED: '.$executionId,0,$error);
            }

            $this->repository->releasePaperBalanceReservation($organizationId,$sellInventoryReservation);
            $this->repository->releasePaperBalanceReservation($organizationId,$buyCashReservation);
            $this->repository->releaseReservation($organizationId,$reservationId);
            throw $error;
        }
    }

    /** @param array<string,mixed> $opportunity @param array<string,mixed> $candidate @param array<string,mixed> $parameters @param array<string,mixed> $risk @param array<string,mixed> $executionEvidence @return array<string,mixed> */
    private function executeOrderBookPath(
        string $organizationId,
        string $opportunityId,
        string $executionId,
        array $opportunity,
        array $candidate,
        array $parameters,
        array $risk,
        \Domains\CapitalMarkets\Domain\MarketData\MarketState $buyState,
        \Domains\CapitalMarkets\Domain\MarketData\MarketState $sellState,
        Decimal $requestedQuantity,
        DateTimeImmutable $now,
        DateTimeImmutable $expires,
        array $executionEvidence,
    ):array{
        $simulation=$this->multiLeg->simulateH2(
            $buyState->orderBook,$sellState->orderBook,$requestedQuantity,CompensationPolicy::EmergencyClose
        );
        if(!$simulation['residual_unhedged_quantity']->isZero()){
            return $this->recordInvalidated(
                $organizationId,$executionId,$opportunityId,$now,'UNHEDGED_POSITION',
                [...$executionEvidence,'residual_unhedged_quantity'=>$simulation['residual_unhedged_quantity']->value()]
            );
        }

        /** @var Decimal $buyQuantity */
        $buyQuantity=$simulation['buy']['filled_quantity'];
        /** @var Decimal $sellQuantity */
        $sellQuantity=$simulation['sell']['filled_quantity'];
        /** @var Decimal $buyPrice */
        $buyPrice=$simulation['buy']['price'];
        /** @var Decimal $sellPrice */
        $sellPrice=$simulation['sell']['price'];
        /** @var Decimal $buyNotional */
        $buyNotional=$simulation['buy']['notional'];
        /** @var Decimal $sellNotional */
        $sellNotional=$simulation['sell']['notional'];

        if(!$buyQuantity->isPositive()){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'NO_BUY_LIQUIDITY',$executionEvidence);
        }

        $buyFeeRate=Decimal::fromString((string)($parameters['buy_fee_rate']??''));
        $sellFeeRate=Decimal::fromString((string)($parameters['sell_fee_rate']??''));
        $buyFee=DecimalMath::multiply($buyNotional,$buyFeeRate);
        $sellFee=DecimalMath::multiply($sellNotional,$sellFeeRate);

        $detectedBuy=Decimal::fromString((string)$candidate['buy_price']);
        $detectedSell=Decimal::fromString((string)$candidate['sell_price']);
        $buySlipUnit=DecimalMath::subtract($buyPrice,$detectedBuy);
        if($buySlipUnit->isNegative())$buySlipUnit=Decimal::fromString('0');
        $sellSlipUnit=$sellQuantity->isPositive()?DecimalMath::subtract($detectedSell,$sellPrice):Decimal::fromString('0');
        if($sellSlipUnit->isNegative())$sellSlipUnit=Decimal::fromString('0');
        $buySlippage=DecimalMath::multiply($buySlipUnit,$buyQuantity);
        $sellSlippage=DecimalMath::multiply($sellSlipUnit,$sellQuantity);

        $compensation=$simulation['compensation'];
        $compensationFill=null;
        $compensationFee=Decimal::fromString('0');
        $compensationCash=Decimal::fromString('0');
        if(is_array($compensation)&&$compensation['filled_quantity']->isPositive()){
            $compensationFee=DecimalMath::multiply($compensation['notional'],$sellFeeRate);
            $compensationCash=DecimalMath::subtract($compensation['notional'],$compensationFee);
            $compensationFill=new PaperFill(
                $executionId.':compensation',$executionId,
                (string)$candidate['buy_venue_id'],(string)$candidate['buy_instrument_id'],ExecutionSide::Sell,
                $compensation['filled_quantity'],$compensation['price'],$compensationFee,Decimal::fromString('0'),
                $now,$executionId.':compensation'
            );
        }

        $quoteAsset=$buyState->bestQuote->askPrice->quoteAsset->value();
        $requiredCapital=DecimalMath::add($buyNotional,$buyFee);
        $reservationId='cm_res_'.substr(hash('sha256',$opportunityId.'|'.$executionId),0,40);
        $buyCashReservation=$reservationId.':buy-cash';
        $sellInventoryReservation=$sellQuantity->isPositive()?$reservationId.':sell-inventory':null;

        if(!$this->repository->reserveCapital(
            $organizationId,$reservationId,$opportunityId,$requiredCapital->value(),$expires->format(DATE_ATOM)
        )){
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'INSUFFICIENT_PAPER_CAPITAL',$executionEvidence);
        }
        if(!$this->repository->reservePaperBalance(
            $organizationId,$buyCashReservation,$opportunityId,(string)$candidate['buy_venue_id'],$quoteAsset,
            $requiredCapital->value(),$expires->format(DATE_ATOM)
        )){
            $this->repository->releaseReservation($organizationId,$reservationId);
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'INSUFFICIENT_PREFUNDED_BUY_CASH',$executionEvidence);
        }
        if($sellInventoryReservation!==null&&!$this->repository->reservePaperBalance(
            $organizationId,$sellInventoryReservation,$opportunityId,(string)$candidate['sell_venue_id'],(string)$candidate['sell_instrument_id'],
            $sellQuantity->value(),$expires->format(DATE_ATOM)
        )){
            $this->repository->releasePaperBalanceReservation($organizationId,$buyCashReservation);
            $this->repository->releaseReservation($organizationId,$reservationId);
            return $this->recordInvalidated($organizationId,$executionId,$opportunityId,$now,'INSUFFICIENT_PREFUNDED_INVENTORY',$executionEvidence);
        }

        $buyLeg=new ExecutionLeg(
            $executionId.':leg:buy',1,(string)$candidate['buy_venue_id'].':'.(string)$candidate['buy_instrument_id'],
            (string)$candidate['buy_instrument_id'],ExecutionSide::Buy,$requestedQuantity,'IOC',null,$buyPrice,$buyFee,$buySlippage
        );
        $sellTargetPrice=$sellQuantity->isPositive()?$sellPrice:Decimal::fromString((string)$candidate['sell_price']);
        $sellLeg=new ExecutionLeg(
            $executionId.':leg:sell',2,(string)$candidate['sell_venue_id'].':'.(string)$candidate['sell_instrument_id'],
            (string)$candidate['sell_instrument_id'],ExecutionSide::Sell,$buyQuantity,'IOC',null,$sellTargetPrice,$sellFee,$sellSlippage
        );
        $reservationIds=[$reservationId,$buyCashReservation];
        if($sellInventoryReservation!==null)$reservationIds[]=$sellInventoryReservation;
        $plan=new ExecutionPlan(
            $executionId.':plan',$opportunityId,'TokenizedEquityRelativeValue-v1',$now,$expires,[$buyLeg,$sellLeg],
            'SEQUENTIAL',PartialFillPolicy::AbortAndCompensate,CompensationPolicy::EmergencyClose,
            (int)($parameters['max_total_latency_ms']??1000),(int)($parameters['max_leg_latency_ms']??500),
            DecimalMath::add(DecimalMath::add($buyFee,$sellFee),$compensationFee),
            Decimal::fromString((string)$opportunity['expected_pnl']),(string)($risk['id']??'unknown'),$reservationIds
        );
        $this->repository->saveExecutionPlan($organizationId,$plan->id,$opportunityId,$this->planArray($plan,'READY'));

        $checkpoint=[
            'id'=>$executionId,'opportunity_id'=>$opportunityId,'status'=>'READY','checkpoint'=>'RESERVATIONS_READY',
            'executed_at'=>$now->format(DATE_ATOM),'reservation_id'=>$reservationId,
            'buy_cash_reservation_id'=>$buyCashReservation,'sell_inventory_reservation_id'=>$sellInventoryReservation,
            'buy_venue_id'=>(string)$candidate['buy_venue_id'],'sell_venue_id'=>(string)$candidate['sell_venue_id'],
            'buy_instrument_id'=>(string)$candidate['buy_instrument_id'],'sell_instrument_id'=>(string)$candidate['sell_instrument_id'],
            'quote_asset'=>$quoteAsset,'quantity'=>$requestedQuantity->value(),'plan_id'=>$plan->id,
            'realized_pnl'=>'0','edge_capture_ratio'=>'0',
        ];
        $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'READY',$checkpoint);

        $buyOrder=new PaperOrder(
            $executionId.':order:buy',$executionId,$buyLeg->id,$buyLeg->venueMarketId,(string)$candidate['buy_instrument_id'],
            ExecutionSide::Buy,$requestedQuantity,$buyQuantity,$simulation['buy']['state'],$now,$now,$now
        );
        $this->repository->savePaperOrder(
            $organizationId,$buyOrder->id,$executionId,$buyLeg->id,$buyOrder->state->value,$buyOrder->id,$this->orderArray($buyOrder)
        );
        $buyFill=new PaperFill(
            $executionId.':buy',$executionId,(string)$candidate['buy_venue_id'],(string)$candidate['buy_instrument_id'],
            ExecutionSide::Buy,$buyQuantity,$buyPrice,$buyFee,$buySlippage,$now,$executionId.':buy'
        );
        $this->repository->savePaperFill(
            $organizationId,$buyFill->id,$buyOrder->id,$executionId,$buyFill->idempotencyKey,$this->fillArray($buyFill)
        );
        $checkpoint=[
            ...$checkpoint,'status'=>'PARTIALLY_EXECUTED','checkpoint'=>'LEG1_FILLED',
            'buy_order'=>$this->orderArray($buyOrder),'buy_fill'=>$this->fillArray($buyFill),
        ];
        $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'PARTIALLY_EXECUTED',$checkpoint);

        $sellOrder=new PaperOrder(
            $executionId.':order:sell',$executionId,$sellLeg->id,$sellLeg->venueMarketId,(string)$candidate['sell_instrument_id'],
            ExecutionSide::Sell,$buyQuantity,$sellQuantity,$simulation['sell']['state'],$now,$now,$now
        );
        $this->repository->savePaperOrder(
            $organizationId,$sellOrder->id,$executionId,$sellLeg->id,$sellOrder->state->value,$sellOrder->id,$this->orderArray($sellOrder)
        );

        $sellFill=null;
        if($sellQuantity->isPositive()){
            $sellFill=new PaperFill(
                $executionId.':sell',$executionId,(string)$candidate['sell_venue_id'],(string)$candidate['sell_instrument_id'],
                ExecutionSide::Sell,$sellQuantity,$sellPrice,$sellFee,$sellSlippage,$now,$executionId.':sell'
            );
            $this->repository->savePaperFill(
                $organizationId,$sellFill->id,$sellOrder->id,$executionId,$sellFill->idempotencyKey,$this->fillArray($sellFill)
            );
        }
        $checkpoint=[
            ...$checkpoint,
            'status'=>$compensationFill!==null?'COMPENSATING':'EXECUTING',
            'checkpoint'=>$compensationFill!==null?($sellQuantity->isPositive()?'LEG2_PARTIAL':'LEG2_REJECTED'):'LEGS_MATCHED',
            'sell_order'=>$this->orderArray($sellOrder),
            'sell_fill'=>$sellFill===null?null:$this->fillArray($sellFill),
        ];
        $this->repository->saveExecution(
            $organizationId,$executionId,$opportunityId,$compensationFill!==null?'COMPENSATING':'EXECUTING',$checkpoint
        );

        $compOrder=null;
        if($compensationFill!==null){
            $compOrder=new PaperOrder(
                $executionId.':order:compensation',$executionId,$executionId.':leg:compensation',
                (string)$candidate['buy_venue_id'].':'.(string)$candidate['buy_instrument_id'],
                (string)$candidate['buy_instrument_id'],ExecutionSide::Sell,
                $compensationFill->quantity,$compensationFill->quantity,PaperOrderState::Filled,$now,$now,$now
            );
            $this->repository->savePaperOrder(
                $organizationId,$compOrder->id,$executionId,$compOrder->legId,$compOrder->state->value,$compOrder->id,$this->orderArray($compOrder)
            );
            $this->repository->savePaperFill(
                $organizationId,$compensationFill->id,$compOrder->id,$executionId,$compensationFill->idempotencyKey,$this->fillArray($compensationFill)
            );
            $checkpoint=[
                ...$checkpoint,'status'=>'EXECUTING','checkpoint'=>'COMPENSATION_FILLED',
                'compensation_order'=>$this->orderArray($compOrder),
                'compensation_fill'=>$this->fillArray($compensationFill),
                'compensation_quantity'=>$compensationFill->quantity->value(),
                'compensation_cash'=>$compensationCash->value(),
            ];
            $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'EXECUTING',$checkpoint);
        }

        $fills=[$buyFill];
        if($sellFill!==null)$fills[]=$sellFill;
        if($compensationFill!==null)$fills[]=$compensationFill;
        $realized=$this->pnl->realized($fills);

        $ledgerEntries=[];
        foreach($fills as $fill)$ledgerEntries=[...$ledgerEntries,...$this->ledgerEntriesForFill($fill,$quoteAsset)];
        $ledger=new LedgerTransaction(
            'cm_ledger_'.substr(hash('sha256',$executionId.'|settlement'),0,40),$executionId.':settlement',$now,$ledgerEntries
        );
        $this->repository->saveLedgerTransaction($organizationId,$ledger->id,$ledger->idempotencyKey,[
            'id'=>$ledger->id,'idempotency_key'=>$ledger->idempotencyKey,'posted_at'=>$ledger->postedAt->format(DATE_ATOM),
            'entries'=>array_map(static fn(LedgerEntry $e):array=>[
                'account'=>$e->account,'asset_key'=>$e->assetKey,'debit'=>$e->debit->value(),'credit'=>$e->credit->value(),
            ],$ledger->entries),
        ]);

        $effectiveQuantity=$sellQuantity->isPositive()?$sellQuantity:$buyQuantity;
        $detectedEdge=Decimal::fromString((string)$candidate['gross_spread']);
        $executableEdge=$sellQuantity->isPositive()?DecimalMath::subtract($sellPrice,$buyPrice):Decimal::fromString('0');
        $realizedEdge=$effectiveQuantity->isPositive()?DecimalMath::divide($realized,$effectiveQuantity,12):Decimal::fromString('0');
        $performance=new ExecutionPerformance(
            $executionId,$detectedEdge,$executableEdge,$realizedEdge,
            Decimal::fromString((string)$opportunity['expected_pnl']),$realized,$requiredCapital,0
        );

        $checkpoint=[
            ...$checkpoint,'status'=>'EXECUTING','checkpoint'=>'LEDGER_POSTED',
            'realized_pnl'=>$realized->value(),'edge_capture_ratio'=>$performance->edgeCaptureRatio->value(),
        ];
        $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'EXECUTING',$checkpoint);
        $this->repository->saveExecutionPlan($organizationId,$plan->id,$opportunityId,$this->planArray($plan,'ACCOUNTING'));

        $sellCash=$sellQuantity->isPositive()?DecimalMath::subtract($sellNotional,$sellFee):Decimal::fromString('0');
        $this->repository->settlePaperExecution(
            $organizationId,$executionId,$reservationId,$buyCashReservation,$sellInventoryReservation,
            (string)$candidate['buy_venue_id'],(string)$candidate['buy_instrument_id'],$buyQuantity->value(),
            (string)$candidate['sell_venue_id'],$quoteAsset,$sellCash->value(),$realized->value(),
            $compensationFill?->quantity->value(),$compensationFill===null?null:$compensationCash->value()
        );
        $buyVenueFills=[$buyFill];
        if($compensationFill!==null)$buyVenueFills[]=$compensationFill;
        $markPrice=$compensationFill?->price??$buyPrice;
        $this->persistBuyVenuePosition(
            $organizationId,$executionId,(string)$candidate['buy_venue_id'],(string)$candidate['buy_instrument_id'],
            $buyVenueFills,$markPrice
        );

        $status=$compensationFill!==null?'COMPLETED_COMPENSATED':'COMPLETED';
        $payload=[
            ...$checkpoint,
            'status'=>$status,'checkpoint'=>'SETTLED','requested_quantity'=>$requestedQuantity->value(),
            'buy_filled_quantity'=>$buyQuantity->value(),'sell_filled_quantity'=>$sellQuantity->value(),
            'buy_fill'=>$this->fillArray($buyFill),
            'sell_fill'=>$sellFill===null?null:$this->fillArray($sellFill),
            'compensation_fill'=>$compensationFill===null?null:$this->fillArray($compensationFill),
            'detected_edge'=>$detectedEdge->value(),'executable_edge'=>$executableEdge->value(),
            'realized_edge'=>$realizedEdge->value(),'expected_pnl'=>$opportunity['expected_pnl'],
            'realized_pnl'=>$realized->value(),'edge_capture_ratio'=>$performance->edgeCaptureRatio->value(),
            'partial_fill'=>!$simulation['buy']['fully_filled']||!$simulation['sell']['fully_filled'],
            'compensated'=>$compensationFill!==null,'residual_unhedged_quantity'=>'0',
        ];
        $this->repository->saveExecutionPlan($organizationId,$plan->id,$opportunityId,$this->planArray($plan,$status));
        $this->repository->saveExecution($organizationId,$executionId,$opportunityId,$status,$payload);

        $hypothesis=(string)($opportunity['hypothesis']??'H2');
        $fingerprint=hash('sha256',implode('|',[$organizationId,$hypothesis,'EXECUTION',$opportunityId,$executionId]));
        $this->repository->saveHypothesisObservation(
            $organizationId,'cm_obs_'.substr($fingerprint,0,40),$hypothesis,'EXECUTION',$now->format(DATE_ATOM),$fingerprint,[
                'market_pair_id'=>(string)($candidate['market_pair_id']??''),'candidate_id'=>(string)($candidate['id']??''),
                'opportunity_id'=>$opportunityId,'execution_id'=>$executionId,'detected'=>true,'executable'=>true,'realized'=>true,
                'expected_pnl'=>(string)$opportunity['expected_pnl'],'realized_pnl'=>$realized->value(),
                'reason'=>$compensationFill!==null?'COMPENSATED_PARTIAL_FILL':null,
                'edge_capture_ratio'=>$performance->edgeCaptureRatio->value(),
            ]
        );

        $this->recordExecutionTelemetry(
            $organizationId,(string)($payload['status']??'COMPLETED'),
            Decimal::fromString((string)($payload['realized_pnl']??'0')),
            Decimal::fromString((string)($payload['edge_capture_ratio']??'0'))
        );
        return $payload;
    }

    /** @return list<LedgerEntry> */
    private function ledgerEntriesForFill(PaperFill $fill,string $quoteAsset):array
    {
        if($fill->side===ExecutionSide::Buy){
            return [
                new LedgerEntry('venue:'.$fill->venueId.':inventory',$fill->quantity,Decimal::fromString('0'),$fill->instrumentId),
                new LedgerEntry('external:'.$fill->venueId.':inventory',Decimal::fromString('0'),$fill->quantity,$fill->instrumentId),
                new LedgerEntry('external:'.$fill->venueId.':cash',$fill->notional(),Decimal::fromString('0'),$quoteAsset),
                new LedgerEntry('venue:'.$fill->venueId.':cash',Decimal::fromString('0'),$fill->notional(),$quoteAsset),
                new LedgerEntry('fees:'.$fill->venueId,$fill->fee,Decimal::fromString('0'),$quoteAsset),
                new LedgerEntry('venue:'.$fill->venueId.':cash',Decimal::fromString('0'),$fill->fee,$quoteAsset),
            ];
        }
        return [
            new LedgerEntry('external:'.$fill->venueId.':inventory',$fill->quantity,Decimal::fromString('0'),$fill->instrumentId),
            new LedgerEntry('venue:'.$fill->venueId.':inventory',Decimal::fromString('0'),$fill->quantity,$fill->instrumentId),
            new LedgerEntry('venue:'.$fill->venueId.':cash',$fill->notional(),Decimal::fromString('0'),$quoteAsset),
            new LedgerEntry('external:'.$fill->venueId.':cash',Decimal::fromString('0'),$fill->notional(),$quoteAsset),
            new LedgerEntry('fees:'.$fill->venueId,$fill->fee,Decimal::fromString('0'),$quoteAsset),
            new LedgerEntry('venue:'.$fill->venueId.':cash',Decimal::fromString('0'),$fill->fee,$quoteAsset),
        ];
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function recordInvalidated(
        string $organizationId,
        string $executionId,
        string $opportunityId,
        DateTimeImmutable $at,
        string $reason,
        array $evidence=[],
    ):array{
        $payload=[
            'id'=>$executionId,
            'opportunity_id'=>$opportunityId,
            'status'=>'INVALIDATED',
            'executed_at'=>$at->format(DATE_ATOM),
            'reason'=>$reason,
            'realized_pnl'=>'0',
            'edge_capture_ratio'=>'0',
            'evidence'=>$evidence,
        ];
        $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'INVALIDATED',$payload);
        $this->telemetry->metric($organizationId,'paper_execution_total',1.0,['phase'=>'invalidated','reason'=>$reason]);
        if($reason==='UNHEDGED_POSITION'){
            $this->telemetry->alert($organizationId,CapitalMarketsAlertType::UnhedgedPosition,[
                'execution_id'=>$executionId,'opportunity_id'=>$opportunityId,'reason'=>$reason,
            ]);
        }
        if(str_contains($reason,'LEDGER')){
            $this->telemetry->alert($organizationId,CapitalMarketsAlertType::LedgerInconsistency,[
                'execution_id'=>$executionId,'opportunity_id'=>$opportunityId,'reason'=>$reason,
            ]);
        }

        $hypothesis=(string)($evidence['hypothesis']??'H2');
        $fingerprint=hash('sha256',implode('|',[
            $organizationId,$hypothesis,'EXECUTION',$opportunityId,$executionId,
        ]));
        $this->repository->saveHypothesisObservation(
            $organizationId,
            'cm_obs_'.substr($fingerprint,0,40),
            $hypothesis,
            'EXECUTION',
            $at->format(DATE_ATOM),
            $fingerprint,
            [
                'market_pair_id'=>(string)($evidence['market_pair_id']??''),
                'candidate_id'=>(string)($evidence['candidate_id']??''),
                'opportunity_id'=>$opportunityId,
                'execution_id'=>$executionId,
                'detected'=>true,
                'executable'=>false,
                'realized'=>false,
                'expected_pnl'=>(string)($evidence['expected_pnl']??'0'),
                'realized_pnl'=>'0',
                'reason'=>$reason,
            ]
        );
        return $payload;
    }

    /** @return array{price:Decimal,notional:Decimal} */
    private function executable(\Domains\CapitalMarkets\Domain\MarketData\MarketState $state,ExecutionSide $side,Decimal $quantity):array
    {
        if($state->orderBook!==null){
            $vwap=$this->prices->vwap($state->orderBook,$side,$quantity);
            return ['price'=>$vwap['price'],'notional'=>$vwap['notional']];
        }
        $quote=$state->bestQuote;
        $available=$side===ExecutionSide::Buy?$quote->askQuantity->value:$quote->bidQuantity->value;
        if($available->compareTo($quantity)<0)throw new DomainException('INSUFFICIENT_LIQUIDITY');
        $price=$side===ExecutionSide::Buy?$quote->askPrice->value:$quote->bidPrice->value;
        return ['price'=>$price,'notional'=>DecimalMath::multiply($price,$quantity)];
    }

    /** @param list<PaperFill> $fills */
    private function persistBuyVenuePosition(
        string $organizationId,string $executionId,string $venueId,string $instrumentId,array $fills,Decimal $markPrice
    ):void{
        $portfolioId='paper:'.$executionId;
        $position=$this->positions->project(
            $portfolioId,'TokenizedEquityRelativeValue-v1',$instrumentId,$venueId,$fills,$markPrice
        );
        $payload=[
            'position_id'=>$position->positionId,'portfolio_id'=>$position->portfolioId,'strategy_id'=>$position->strategyId,
            'instrument_id'=>$position->instrumentId,'venue_id'=>$position->venueId,'status'=>$position->status(),
            'instrument_kind'=>'TOKENIZED_EQUITY','side'=>'LONG',
            'quantity'=>$position->quantity->value(),'average_entry_price'=>$position->averageEntryPrice->value(),
            'mark_price'=>$position->markPrice->value(),'market_value'=>$position->marketValue()->value(),
            'fees'=>$position->fees->value(),'realized_pnl'=>$position->realizedPnl->value(),
            'unrealized_pnl'=>$position->unrealizedPnl()->value(),
            'opened_at'=>$position->openedAt?->format(DATE_ATOM),'updated_at'=>$position->updatedAt?->format(DATE_ATOM),
            'closed_at'=>$position->closedAt?->format(DATE_ATOM),
        ];
        $this->repository->savePosition(
            $organizationId,(string)$position->positionId,$portfolioId,'TokenizedEquityRelativeValue-v1',
            $instrumentId,$venueId,$position->status(),$payload
        );
    }

    private function recordExecutionTelemetry(
        string $organizationId,string $status,Decimal $realizedPnl,Decimal $edgeCapture
    ):void{
        $this->telemetry->metric($organizationId,'paper_execution_total',1.0,['phase'=>'completed','status'=>$status]);
        $this->telemetry->metric($organizationId,'paper_execution_realized_pnl',(float)$realizedPnl->value(),['status'=>$status]);
        $this->telemetry->metric($organizationId,'paper_execution_edge_capture_ratio',(float)$edgeCapture->value(),['status'=>$status]);
        if($status==='COMPLETED_COMPENSATED'){
            $this->telemetry->metric($organizationId,'paper_execution_compensation_total',1.0,['status'=>$status]);
        }
    }

    /** @return array<string,mixed> */
    private function planArray(ExecutionPlan $plan,string $status):array
    {
        return [
            'id'=>$plan->id,'opportunity_id'=>$plan->opportunityId,'strategy_version'=>$plan->strategyVersion,
            'status'=>$status,'created_at'=>$plan->createdAt->format(DATE_ATOM),'expires_at'=>$plan->expiresAt->format(DATE_ATOM),
            'sequence_policy'=>$plan->sequencePolicy,'partial_fill_policy'=>$plan->partialFillPolicy->value,
            'compensation_policy'=>$plan->compensationPolicy->value,'max_total_latency_ms'=>$plan->maxTotalLatencyMs,
            'max_leg_latency_ms'=>$plan->maxLegLatencyMs,'expected_cost'=>$plan->expectedCost->value(),
            'expected_pnl'=>$plan->expectedPnl->value(),'risk_assessment_id'=>$plan->riskAssessmentId,
            'capital_reservation_ids'=>$plan->capitalReservationIds,
            'legs'=>array_map(fn(ExecutionLeg $leg):array=>$this->legArray($leg),$plan->legs),
        ];
    }

    /** @return array<string,mixed> */
    private function legArray(ExecutionLeg $leg):array
    {
        return [
            'id'=>$leg->id,'sequence'=>$leg->sequence,'venue_market_id'=>$leg->venueMarketId,
            'instrument_id'=>$leg->instrumentId,'side'=>$leg->side->value,'quantity'=>$leg->quantity->value(),
            'order_type'=>$leg->orderType,'limit_price'=>$leg->limitPrice?->value(),'target_price'=>$leg->targetPrice->value(),
            'expected_fee'=>$leg->expectedFee->value(),'expected_slippage'=>$leg->expectedSlippage->value(),
        ];
    }

    /** @return array<string,mixed> */
    private function orderArray(PaperOrder $order):array
    {
        return [
            'id'=>$order->id,'execution_group_id'=>$order->executionGroupId,'leg_id'=>$order->legId,
            'venue_market_id'=>$order->venueMarketId,'instrument_id'=>$order->instrumentId,'side'=>$order->side->value,
            'requested_quantity'=>$order->requestedQuantity->value(),'filled_quantity'=>$order->filledQuantity->value(),
            'remaining_quantity'=>$order->remainingQuantity()->value(),'state'=>$order->state->value,
            'created_at'=>$order->createdAt->format(DATE_ATOM),'submitted_at'=>$order->submittedAt?->format(DATE_ATOM),
            'filled_at'=>$order->filledAt?->format(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed> */
    private function fillArray(PaperFill $fill):array
    {
        return [
            'id'=>$fill->id,'venue_id'=>$fill->venueId,'instrument_id'=>$fill->instrumentId,'side'=>$fill->side->value,
            'quantity'=>$fill->quantity->value(),'price'=>$fill->price->value(),'notional'=>$fill->notional()->value(),
            'fee'=>$fill->fee->value(),'slippage'=>$fill->slippage->value(),'filled_at'=>$fill->filledAt->format(DATE_ATOM),
            'idempotency_key'=>$fill->idempotencyKey,
        ];
    }
}
