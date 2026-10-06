<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Portfolio;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class EconomicExposure extends ValueObject
{
    /** @param array<string,Decimal> $components */
    public function __construct(
        public string $exposureKey,
        public string $currency,
        public array $components,
    ){
        if(trim($exposureKey)===''||trim($currency)==='')throw new InvalidArgumentException('Economic exposure identity is required.');
        foreach($components as $name=>$value){
            if(!is_string($name)||$name===''||!$value instanceof Decimal){
                throw new InvalidArgumentException('Economic exposure components must be named Decimal values.');
            }
        }
    }

    public function net():Decimal
    {
        $net=Decimal::fromString('0');
        foreach($this->components as $value)$net=DecimalMath::add($net,$value);
        return $net;
    }
}
