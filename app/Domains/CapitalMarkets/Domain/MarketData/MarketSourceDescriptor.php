<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketSourceDescriptor extends ValueObject
{
    /**
     * @param list<MarketSourceRole> $roles
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public MarketSourceId $id,
        public ?VenueId $venueId,
        public string $adapterType,
        public bool $enabled,
        public int $priority,
        public array $roles,
        public ?string $credentialsReference,
        public RateLimitPolicy $rateLimitPolicy,
        public ReconnectPolicy $reconnectPolicy,
        public MarketHealthPolicy $healthPolicy,
        public array $metadata=[],
        public string $licenseProfile='UNSPECIFIED',
        public ?MarketDataQualityPolicy $qualityPolicy=null,
    ){
        if($this->adapterType===''||trim($this->adapterType)!==$this->adapterType||mb_strlen($this->adapterType)>120){
            throw new InvalidArgumentException('Market source adapter type is invalid.');
        }
        if($this->priority<0||$this->priority>10000)throw new InvalidArgumentException('Market source priority must be between 0 and 10000.');
        if($this->roles===[])throw new InvalidArgumentException('Market source must declare at least one role.');
        foreach($this->roles as $role){
            if(!$role instanceof MarketSourceRole)throw new InvalidArgumentException('Market source roles must be typed.');
        }
        if(count(array_unique(array_map(static fn(MarketSourceRole $role):string=>$role->value,$this->roles)))!==count($this->roles)){
            throw new InvalidArgumentException('Market source roles cannot contain duplicates.');
        }
        if($this->credentialsReference!==null&&($this->credentialsReference===''||trim($this->credentialsReference)!==$this->credentialsReference||mb_strlen($this->credentialsReference)>190)){
            throw new InvalidArgumentException('Market source credentials reference is invalid.');
        }
        if($this->licenseProfile===''||trim($this->licenseProfile)!==$this->licenseProfile||mb_strlen($this->licenseProfile)>120){
            throw new InvalidArgumentException('Market source license profile is invalid.');
        }
        InstrumentDescriptor::assertMetadata($this->metadata);
    }

    public function hasRole(MarketSourceRole $role):bool
    {
        return in_array($role,$this->roles,true);
    }
}
