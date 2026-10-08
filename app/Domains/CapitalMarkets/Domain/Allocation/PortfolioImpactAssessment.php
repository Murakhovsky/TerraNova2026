<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Allocation;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioDecisionState;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class PortfolioImpactAssessment {
 public function __construct(public string $opportunityId,public Decimal $capitalRequired,public Decimal $capitalAfter,public Decimal $grossExposureChange,public Decimal $netExposureChange,public array $venueExposureChange,public array $strategyExposureChange,public array $assetExposureChange,public Decimal $marginChange,public int $liquidityChange,public int $riskScoreChange,public Decimal $correlationEffect,public PortfolioDecisionState $decision,public array $reasons=[]){}
}
