<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\RelativeValueResearchRepositoryInterface;
use Domains\CapitalMarkets\Domain\Event\FundingSettled;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\FundingSettlement;
use Domains\CapitalMarkets\Domain\Portfolio\Position;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Service\FundingCashflowCalculator;
use Domains\CapitalMarkets\Domain\Service\FundingSettlementEligibility;
use Domains\CapitalMarkets\Domain\Ledger\LedgerEntry;
use Domains\CapitalMarkets\Domain\Ledger\LedgerTransaction;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final readonly class FundingSettlementService
{
    public function __construct(
        private CapitalMarketsTradingRepositoryInterface $trading,
        private RelativeValueResearchRepositoryInterface $research,
        private FundingCashflowCalculator $cashflows,
        private FundingSettlementEligibility $eligibility,
    ){}

    /** @return array<string,mixed> */
    public function settle(
        string $organizationId,
        string $positionId,
        FundingRateObservation $funding,
        Decimal $settlementMarkPrice,
        AssetCode $currency,
    ):array{
        if($funding->status!==FundingRateStatus::Settled)throw new DomainException('FUNDING_SETTLEMENT_REQUIRES_SETTLED_RATE');
        if(!$settlementMarkPrice->isPositive())throw new DomainException('FUNDING_SETTLEMENT_MARK_PRICE_INVALID');

        $payload=$this->position($organizationId,$positionId);
        $position=$this->hydrate($payload);
        $settlementAt=$funding->observationTimestamp;

        if($position->venueId!==$funding->venue->value()||$position->instrumentId!==$funding->perpetualInstrument->value()){
            throw new DomainException('FUNDING_POSITION_MARKET_MISMATCH');
        }
        if(!$this->eligibility->eligible($position,$settlementAt)){
            throw new DomainException('POSITION_NOT_ELIGIBLE_FOR_FUNDING_SETTLEMENT');
        }

        $multiplier=$position->contractMultiplier??Decimal::fromString('1');
        $notional=DecimalMath::multiply(
            DecimalMath::multiply($position->quantity,$multiplier),
            $settlementMarkPrice
        );
        $cashflow=$this->cashflows->calculate($funding,$notional,$position->side);
        $settlementId='cm_funding_settlement_'.substr(hash('sha256',implode('|',[
            $organizationId,$positionId,$funding->venue->value(),$funding->perpetualInstrument->value(),
            $settlementAt->format('U.u'),$funding->rate->value()
        ])),0,40);

        $settlement=new FundingSettlement(
            $funding->venue,$funding->perpetualInstrument,$positionId,$settlementAt,$funding->rate,
            $notional,$position->side,$cashflow,$currency,$funding->source
        );
        $this->research->saveFundingSettlement($organizationId,$settlementId,$settlement);

        $this->trading->adjustPaperBalance(
            $organizationId,$position->venueId,$currency->value(),$cashflow->value()
        );

        if(!$cashflow->isZero()){
            $amount=DecimalMath::abs($cashflow);
            $received=$cashflow->isPositive();
            $entries=$received
                ?[
                    new LedgerEntry('venue:'.$position->venueId.':cash',$amount,Decimal::fromString('0'),$currency->value()),
                    new LedgerEntry('funding_income:'.$position->strategyId,Decimal::fromString('0'),$amount,$currency->value()),
                ]
                :[
                    new LedgerEntry('funding_expense:'.$position->strategyId,$amount,Decimal::fromString('0'),$currency->value()),
                    new LedgerEntry('venue:'.$position->venueId.':cash',Decimal::fromString('0'),$amount,$currency->value()),
                ];
            $ledger=new LedgerTransaction(
                'cm_ledger_'.substr(hash('sha256',$settlementId),0,40),
                $settlementId,
                $settlementAt,
                $entries,
            );
            $this->trading->saveLedgerTransaction($organizationId,$ledger->id,$ledger->idempotencyKey,[
                'id'=>$ledger->id,'type'=>$received?'FUNDING_RECEIVED':'FUNDING_PAID',
                'idempotency_key'=>$ledger->idempotencyKey,'posted_at'=>$ledger->postedAt->format(DATE_ATOM),
                'position_reference'=>$positionId,'funding_settlement_id'=>$settlementId,
                'entries'=>array_map(static fn(LedgerEntry $entry):array=>[
                    'account'=>$entry->account,'asset_key'=>$entry->assetKey,
                    'debit'=>$entry->debit->value(),'credit'=>$entry->credit->value(),
                ],$ledger->entries),
            ]);
        }

        $event=new FundingSettled(
            $positionId,$position->venueId,$position->instrumentId,$funding->rate,$notional,$cashflow,$settlementAt
        );

        return [
            'settlement_id'=>$settlementId,'position_reference'=>$positionId,'venue_id'=>$position->venueId,
            'instrument_id'=>$position->instrumentId,'settlement_at'=>$settlementAt->format(DATE_ATOM),
            'rate'=>$funding->rate->value(),'position_notional'=>$notional->value(),'side'=>$position->side->value,
            'gross_cashflow'=>$cashflow->value(),'currency'=>$currency->value(),
            'event'=>[
                'type'=>'FundingSettled','position_reference'=>$event->positionReference,
                'venue_id'=>$event->venueId,'instrument_id'=>$event->instrumentId,
                'rate'=>$event->rate->value(),'notional'=>$event->notional->value(),
                'cashflow'=>$event->cashflow->value(),'occurred_at'=>$event->occurredAt->format(DATE_ATOM),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function position(string $organizationId,string $positionId):array
    {
        foreach($this->trading->listPositions($organizationId,5000) as $row){
            if((string)($row['position_id']??'')===$positionId)return $row;
        }
        throw new DomainException('POSITION_NOT_FOUND');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row):Position
    {
        $side=PositionSide::tryFrom((string)($row['side']??'LONG'))??PositionSide::Long;
        return new Position(
            (string)$row['instrument_id'],(string)$row['venue_id'],
            Decimal::fromString((string)$row['quantity']),
            Decimal::fromString((string)$row['average_entry_price']),
            Decimal::fromString((string)$row['mark_price']),
            Decimal::fromString((string)($row['fees']??'0')),
            Decimal::fromString((string)($row['realized_pnl']??'0')),
            (string)$row['position_id'],(string)($row['portfolio_id']??'paper'),
            (string)($row['strategy_id']??'relative-value'),
            isset($row['opened_at'])&&$row['opened_at']!==null?new DateTimeImmutable((string)$row['opened_at']):null,
            isset($row['updated_at'])&&$row['updated_at']!==null?new DateTimeImmutable((string)$row['updated_at']):null,
            isset($row['closed_at'])&&$row['closed_at']!==null?new DateTimeImmutable((string)$row['closed_at']):null,
            $side,
            isset($row['contract_multiplier'])&&$row['contract_multiplier']!==null
                ?Decimal::fromString((string)$row['contract_multiplier'])
                :null,
        );
    }
}
