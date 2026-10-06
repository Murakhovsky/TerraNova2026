<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class SpotProfile extends ValueObject
{
    public function __construct(
        public AssetCode $baseAsset,
        public AssetCode $quoteAsset,
        public Decimal $minimumQuantity,
        public Decimal $minimumNotional,
        public int $pricePrecision,
        public int $quantityPrecision,
        public bool $marginCapability=false,
        public bool $shortCapability=false,
        public bool $borrowCapability=false,
    ){
        if($minimumQuantity->isNegative()||$minimumNotional->isNegative()){
            throw new InvalidArgumentException('Spot minimum quantity/notional cannot be negative.');
        }
        if($pricePrecision<0||$pricePrecision>30||$quantityPrecision<0||$quantityPrecision>30){
            throw new InvalidArgumentException('Spot precision must be between 0 and 30.');
        }
        if($shortCapability&&!$marginCapability)throw new InvalidArgumentException('Spot short capability requires margin capability.');
        if($borrowCapability&&!$marginCapability)throw new InvalidArgumentException('Spot borrow capability requires margin capability.');
    }
}
