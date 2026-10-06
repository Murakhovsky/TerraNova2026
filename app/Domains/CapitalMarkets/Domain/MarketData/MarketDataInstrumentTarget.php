<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketDataInstrumentTarget extends ValueObject
{
    public function __construct(
        public InstrumentDescriptor $instrument,
        public ?VenueInstrument $venueInstrument,
        public string $externalSymbol,
    ){
        if($this->externalSymbol===''||trim($this->externalSymbol)!==$this->externalSymbol||mb_strlen($this->externalSymbol)>190){
            throw new InvalidArgumentException('Market-data external symbol must be a trimmed value up to 190 characters.');
        }
        if($this->venueInstrument!==null&&!$this->venueInstrument->instrumentId->equals($this->instrument->id)){
            throw new InvalidArgumentException('Market-data target venue mapping belongs to another instrument.');
        }
    }
}
