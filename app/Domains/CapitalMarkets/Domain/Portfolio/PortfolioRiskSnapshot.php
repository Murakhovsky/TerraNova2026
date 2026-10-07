<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class PortfolioRiskSnapshot {
 public function __construct(public string $portfolioId,public DateTimeImmutable $timestamp,public Decimal $equity,public Decimal $grossExposure,public Decimal $netExposure,public Decimal $leverage,public Decimal $drawdown,public Decimal $dailyPnl,public array $venueConcentration,public array $assetConcentration,public array $strategyConcentration,public Decimal $marginUtilization,public int $liquidityScore,public array $riskLimitUtilization,public PortfolioRiskState $state=PortfolioRiskState::Normal,public array $alerts=[],public string $valuationQuality='TRUSTED'){}
}
