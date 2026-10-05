<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Venue;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class VenueInstrument extends ValueObject
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public VenueId $venueId,
        public InstrumentId $instrumentId,
        public string $venueSymbol,
        public VenueInstrumentStatus $status,
        public int $pricePrecision,
        public int $quantityPrecision,
        public ?Decimal $minimumQuantity=null,
        public ?Decimal $minimumNotional=null,
        public array $metadata=[],
    ){
        if($this->venueSymbol===''||trim($this->venueSymbol)!==$this->venueSymbol||mb_strlen($this->venueSymbol)>120){
            throw new InvalidArgumentException('Venue symbol must be a trimmed non-empty value up to 120 characters.');
        }
        foreach([$this->pricePrecision,$this->quantityPrecision] as $precision){
            if($precision<0||$precision>30)throw new InvalidArgumentException('Venue precision must be between 0 and 30.');
        }
        if($this->minimumQuantity?->isNegative()||$this->minimumNotional?->isNegative()){
            throw new InvalidArgumentException('Venue minimum quantity/notional cannot be negative.');
        }
        InstrumentDescriptor::assertMetadata($this->metadata);
    }

    public function key():string{return $this->venueId->value().'|'.$this->instrumentId->value();}
}
