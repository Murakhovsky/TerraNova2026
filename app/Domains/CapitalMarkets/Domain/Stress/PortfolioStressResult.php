<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Stress;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class PortfolioStressResult {
 public function __construct(public string $scenarioId,public Decimal $estimatedLoss,public Decimal $marginImpact,public array $positionsAffected,public array $venuesAffected,public array $riskLimitsBreached,public Decimal $capitalRemaining){}
}
