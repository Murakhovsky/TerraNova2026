<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;

final readonly class MarketSourceConfiguration
{
    /**
     * @param list<MarketSourceRole> $roles
     * @param array<string,scalar|null> $metadata
     */
    public function __construct(
        public MarketSourceId $id,
        public ?VenueId $venueId,
        public string $adapterType,
        public bool $enabled,
        public MarketSourcePriority $priority,
        public array $roles,
        public ?string $credentialsReference,
        public RateLimitPolicy $rateLimitPolicy,
        public ReconnectPolicy $reconnectPolicy,
        public MarketHealthPolicy $healthPolicy,
        public string $licenseProfile,
        public array $metadata=[],
    ){
        if(preg_match('/^[a-z][a-z0-9_.-]{2,80}$/',$this->adapterType)!==1){
            throw new InvalidArgumentException('Market source adapter type is invalid.');
        }
        if($this->roles===[])throw new InvalidArgumentException('Market source requires at least one role.');
        foreach($this->roles as $role){
            if(!$role instanceof MarketSourceRole)throw new InvalidArgumentException('Market source role must be typed.');
        }
        if(trim($this->licenseProfile)==='')throw new InvalidArgumentException('Market source license profile is required.');
        if($this->credentialsReference!==null&&trim($this->credentialsReference)===''){
            throw new InvalidArgumentException('Credential reference must be null or non-empty.');
        }
    }

    public function hasRole(MarketSourceRole $role):bool
    {
        foreach($this->roles as $candidate)if($candidate===$role)return true;
        return false;
    }

    /** @return array<string,mixed> */
    public function toArray():array{return [
        'source_id'=>$this->id->value(),
        'venue_id'=>$this->venueId?->value(),
        'adapter_type'=>$this->adapterType,
        'enabled'=>$this->enabled,
        'priority'=>$this->priority->value,
        'roles'=>array_map(static fn(MarketSourceRole $role):string=>$role->value,$this->roles),
        'credentials_reference'=>$this->credentialsReference,
        'rate_limit_policy'=>$this->rateLimitPolicy->toArray(),
        'reconnect_policy'=>$this->reconnectPolicy->toArray(),
        'health_policy'=>$this->healthPolicy->toArray(),
        'license_profile'=>$this->licenseProfile,
        'metadata'=>$this->metadata,
    ];}
}
