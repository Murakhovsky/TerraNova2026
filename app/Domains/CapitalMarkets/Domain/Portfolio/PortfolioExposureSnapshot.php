<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
final readonly class PortfolioExposureSnapshot {
 public function __construct(public string $portfolioId,public DateTimeImmutable $timestamp,public Decimal $grossExposure,public Decimal $netExposure,public array $byUnderlying=[],public array $byAsset=[],public array $byVenue=[],public array $byStrategy=[],public array $byCurrency=[],public array $byCounterparty=[],public array $byChain=[],public array $byLiquidityBucket=[],public array $byInstrumentFamily=[],public array $byIssuer=[],public array $bySector=[],public array $byJurisdiction=[],public array $byCollateral=[],public array $unknownExposure=[]){
  if($portfolioId==='')throw new InvalidArgumentException('Portfolio id is required.');
  if($grossExposure->isNegative())throw new InvalidArgumentException('Gross exposure cannot be negative.');
 }
}
