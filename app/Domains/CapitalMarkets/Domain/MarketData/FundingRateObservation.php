<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class FundingRateObservation extends ValueObject
{
    public function __construct(
        public VenueId $venue,
        public InstrumentId $perpetualInstrument,
        public Decimal $rate,
        public FundingRateType $rateType,
        public DateTimeImmutable $observationTimestamp,
        public ?DateTimeImmutable $nextSettlementAt,
        public int $fundingIntervalSeconds,
        public ?Decimal $cap,
        public ?Decimal $floor,
        public string $source,
        public int $quality,
        public FundingRateStatus $status,
    ){
        if($fundingIntervalSeconds<60)throw new InvalidArgumentException('Funding interval must be venue-derived and >= 60 seconds.');
        if($source===''||trim($source)!==$source)throw new InvalidArgumentException('Funding source is required.');
        if($quality<0||$quality>100)throw new InvalidArgumentException('Funding quality must be between 0 and 100.');
        if($cap!==null&&$floor!==null&&$cap->compareTo($floor)<0)throw new InvalidArgumentException('Funding cap cannot be below floor.');
    }

    public function valid():bool{return $this->status->validForDecision()&&$this->quality>=1;}
}
