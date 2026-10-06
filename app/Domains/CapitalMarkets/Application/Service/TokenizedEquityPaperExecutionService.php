<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\TokenizedEquityVerticalSliceRepositoryInterface;
use Domains\CapitalMarkets\Domain\Execution\ExecutionPerformance;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperFill;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Ledger\LedgerEntry;
use Domains\CapitalMarkets\Domain\Ledger\LedgerTransaction;
use Domains\CapitalMarkets\Domain\Service\ExecutablePriceCalculator;
use Domains\CapitalMarkets\Domain\Service\PaperPnlEngine;
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
        return $this->repository->paperPortfolio($organizationId);
    }

    /** @return array<string,mixed> */
    public function execute(string $organizationId,string $opportunityId):array
    {
        $opportunity=$this->repository->getOpportunity($organizationId,$opportunityId);
        if($opportunity===null)throw new DomainException('Opportunity not found.');
        if(($opportunity['hypothesis']??null)!=='H2'){
            throw new DomainException('H1 paper execution requires a real executable hedge venue.');
        }
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

        $buyState=$this->marketStates->get($organizationId,VenueId::fromString($buyVenue),InstrumentId::fromString($buyInstrument));
        $sellState=$this->marketStates->get($organizationId,VenueId::fromString($sellVenue),InstrumentId::fromString($sellInstrument));
        if($buyState===null||$sellState===null)throw new DomainException('Execution MarketState unavailable.');
        if(!$buyState->quality->status->isUsableForDecision()||!$sellState->quality->status->isUsableForDecision()){
            throw new DomainException('Execution blocked by untrusted market data.');
        }
        if($buyState->bestQuote===null||$sellState->bestQuote===null)throw new DomainException('Execution quote unavailable.');
        if(!$buyState->bestQuote->askPrice->quoteAsset->equals($sellState->bestQuote->bidPrice->quoteAsset)){
            throw new DomainException('Execution quote currencies are not normalized.');
        }

        $buy=$this->executable($buyState,ExecutionSide::Buy,$quantity);
        $sell=$this->executable($sellState,ExecutionSide::Sell,$quantity);
        if($sell['price']->compareTo($buy['price'])<=0)throw new DomainException('OPPORTUNITY_INVALIDATED');

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

        $executionId='cm_exec_'.bin2hex(random_bytes(12));
        $reservationId='cm_res_'.substr(hash('sha256',$opportunityId.'|'.$executionId),0,40);
        $requiredCapital=DecimalMath::add($buy['notional'],$buyFee);
        if(!$this->repository->reserveCapital(
            $organizationId,$reservationId,$opportunityId,$requiredCapital->value(),$expires->format(DATE_ATOM)
        )){
            throw new DomainException('INSUFFICIENT_PAPER_CAPITAL');
        }

        try{
            $buyFill=new PaperFill(
                $executionId.':buy',$executionId,$buyVenue,$buyInstrument,ExecutionSide::Buy,$quantity,$buy['price'],$buyFee,
                $buySlippage,$now,$executionId.':buy'
            );
            $sellFill=new PaperFill(
                $executionId.':sell',$executionId,$sellVenue,$sellInstrument,ExecutionSide::Sell,$quantity,$sell['price'],$sellFee,
                $sellSlippage,$now,$executionId.':sell'
            );
            $realized=$this->pnl->realized([$buyFill,$sellFill]);
            if(!$realized->isPositive()){
                throw new DomainException('OPPORTUNITY_INVALIDATED_AFTER_EXECUTABLE_PRICING');
            }

            $detectedEdge=Decimal::fromString((string)$candidate['gross_spread']);
            $executableEdge=DecimalMath::subtract($sell['price'],$buy['price']);
            $realizedEdge=DecimalMath::divide($realized,$quantity,12);
            $performance=new ExecutionPerformance(
                $executionId,$detectedEdge,$executableEdge,$realizedEdge,
                Decimal::fromString((string)$opportunity['expected_pnl']),$realized,$requiredCapital,0
            );

            $ledger=new LedgerTransaction(
                'cm_ledger_'.bin2hex(random_bytes(12)),$executionId.':result',$now,[
                    new LedgerEntry(
                        $realized->isPositive()?'paper_cash':'paper_realized_pnl',
                        $realized->isPositive()?$realized:Decimal::fromString('0'),
                        $realized->isNegative()?DecimalMath::abs($realized):Decimal::fromString('0'),
                    ),
                    new LedgerEntry(
                        $realized->isPositive()?'paper_realized_pnl':'paper_cash',
                        $realized->isNegative()?DecimalMath::abs($realized):Decimal::fromString('0'),
                        $realized->isPositive()?$realized:Decimal::fromString('0'),
                    ),
                ]
            );

            $payload=[
                'id'=>$executionId,'opportunity_id'=>$opportunityId,'status'=>'COMPLETED',
                'executed_at'=>$now->format(DATE_ATOM),'reservation_id'=>$reservationId,
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
                    'account'=>$e->account,'debit'=>$e->debit->value(),'credit'=>$e->credit->value(),
                ],$ledger->entries),
            ]);
            $this->repository->saveExecution($organizationId,$executionId,$opportunityId,'COMPLETED',$payload);
            $this->repository->completeReservation($organizationId,$reservationId,$realized->value());
            return $payload;
        }catch(\Throwable $error){
            $this->repository->releaseReservation($organizationId,$reservationId);
            throw $error;
        }
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
