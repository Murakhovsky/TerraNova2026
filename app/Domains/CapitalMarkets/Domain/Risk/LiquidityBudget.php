<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class LiquidityBudget {
 public function __construct(public Decimal $maximumIlliquidCapital,public Decimal $maximumStressExitLoss,public int $maximumExitSeconds){}
}
