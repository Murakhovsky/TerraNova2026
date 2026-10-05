<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use InvalidArgumentException;

final readonly class ReferenceMarketState
{
    public function __construct(
        public MarketSourceId $sourceId,
        public InstrumentId $instrumentId,
        public ?MarketQuote $quote,
        public MarketStatus $marketStatus,
        public string $session,
        public ?MarketQuote $lastRegularMarketQuote,
        public ?MarketQuote $lastExtendedQuote,
        public string $currentReferenceType,
        public MarketDataMode $mode,
        public DateTimeImmutable $sourceTimestamp,
        public DateTimeImmutable $updatedAt,
        public MarketDataQualityAssessment $quality,
        public int $stateVersion,
    ){
        if(trim($this->session)===''||trim($this->currentReferenceType)==='')throw new InvalidArgumentException('Reference session context is required.');
        if($this->stateVersion<1)throw new InvalidArgumentException('Reference state version must be positive.');
    }

    public function referenceAgeMs(MarketClock $clock):int{return MarketTime::diffMilliseconds($clock->now(),$this->sourceTimestamp);}

    public function toArray():array{return [
        'source_id'=>$this->sourceId->value(),'instrument_id'=>$this->instrumentId->value(),
        'quote'=>$this->quote?->toArray(),'market_status'=>$this->marketStatus->value,'session'=>$this->session,
        'last_regular_market_quote'=>$this->lastRegularMarketQuote?->toArray(),
        'last_extended_quote'=>$this->lastExtendedQuote?->toArray(),
        'current_reference_type'=>$this->currentReferenceType,'mode'=>$this->mode->value,
        'source_timestamp'=>$this->sourceTimestamp->format(DATE_ATOM),'updated_at'=>$this->updatedAt->format(DATE_ATOM),
        'quality'=>$this->quality->toArray(),'state_version'=>$this->stateVersion,
    ];}
}
