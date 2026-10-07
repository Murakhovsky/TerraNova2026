<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final readonly class RiskBudget {
 public function __construct(public string $strategyVersionId,public Decimal $capitalBudget,public Decimal $maximumDrawdown,public Decimal $maximumVenueExposure,public Decimal $maximumUnhedgedExposure,public Decimal $consumed){}
 public function available():Decimal{$v=DecimalMath::subtract($this->capitalBudget,$this->consumed);return $v->isNegative()?Decimal::fromString('0'):$v;}
}
