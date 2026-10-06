<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Risk;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class LiquidationRiskState extends ValueObject
{
    public function __construct(
        public Decimal $markPrice,
        public ?Decimal $estimatedLiquidationPrice,
        public ?Decimal $distanceToLiquidation,
        public ?Decimal $marginRatio,
        public ?Decimal $maintenanceMargin,
        public LiquidationRiskStatus $status,
        public ?string $modelReference=null,
    ){
        if(!$markPrice->isPositive())throw new InvalidArgumentException('Liquidation risk requires positive mark price.');
        if($distanceToLiquidation?->isNegative()||$marginRatio?->isNegative()||$maintenanceMargin?->isNegative()){
            throw new InvalidArgumentException('Liquidation distances and margin values cannot be negative.');
        }
    }
}
