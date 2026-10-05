<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Venue;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class VenueDescriptor extends ValueObject
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public VenueId $id,
        public string $name,
        public string $code,
        public VenueType $type,
        public VenueStatus $status,
        public ?string $jurisdiction,
        public string $timezone,
        public ?string $baseUrlReference,
        public array $metadata=[],
    ){
        if($this->name===''||trim($this->name)!==$this->name||mb_strlen($this->name)>190){
            throw new InvalidArgumentException('Venue name must be a trimmed non-empty value up to 190 characters.');
        }
        if(preg_match('/^[A-Z0-9][A-Z0-9._-]{1,39}$/',$this->code)!==1){
            throw new InvalidArgumentException('Venue code must be a stable uppercase identifier.');
        }
        if($this->timezone===''||trim($this->timezone)!==$this->timezone||mb_strlen($this->timezone)>80){
            throw new InvalidArgumentException('Venue timezone must be a canonical non-empty value.');
        }
        InstrumentDescriptor::assertMetadata($this->metadata);
    }
}
