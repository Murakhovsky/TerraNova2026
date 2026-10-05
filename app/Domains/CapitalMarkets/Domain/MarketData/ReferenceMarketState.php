<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ReferenceMarketState extends ValueObject
{
    public function __construct(
        public InstrumentId $instrumentId,
        public MarketSourceId $sourceId,
        public ?MarketQuote $currentQuote,
        public MarketSession $session,
        public ?MarketQuote $lastRegularMarketQuote,
        public ?MarketQuote $lastExtendedQuote,
        public ReferenceType $currentReferenceType,
        public int $referenceAgeMilliseconds,
        public MarketDataQualityAssessment $quality,
        public DateTimeImmutable $updatedAt,
        public int $stateVersion,
    ){
        if($this->referenceAgeMilliseconds<0)throw new InvalidArgumentException('Reference age cannot be negative.');
        if($this->stateVersion<1)throw new InvalidArgumentException('Reference state version must be positive.');
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'instrument_id'=>$this->instrumentId->value(),
            'source_id'=>$this->sourceId->value(),
            'session'=>$this->session->value,
            'current_reference_type'=>$this->currentReferenceType->value,
            'current_quote'=>$this->currentQuote?->toArray(),
            'last_regular_market_quote'=>$this->lastRegularMarketQuote?->toArray(),
            'last_extended_quote'=>$this->lastExtendedQuote?->toArray(),
            'reference_age_ms'=>$this->referenceAgeMilliseconds,
            'quality'=>$this->quality->toArray(),
            'updated_at'=>$this->updatedAt->format(DATE_ATOM),
            'state_version'=>$this->stateVersion,
        ];
    }
}
