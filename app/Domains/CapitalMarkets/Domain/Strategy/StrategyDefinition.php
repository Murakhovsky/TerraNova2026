<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Strategy;

use Domains\CapitalMarkets\Domain\Opportunity\OpportunityType;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class StrategyDefinition extends ValueObject
{
    /** @param array<string,mixed> $configuration */
    public function __construct(
        public string $name,
        public int $version,
        public OpportunityType $opportunityType,
        public array $configuration,
    ){
        if($name===''||trim($name)!==$name||$version<1)throw new InvalidArgumentException('Strategy name/version are invalid.');
        if(strlen(json_encode($configuration,JSON_THROW_ON_ERROR))>16384)throw new InvalidArgumentException('Strategy configuration is too large.');
    }

    public function id():string{return $this->name.'-v'.$this->version;}
}
