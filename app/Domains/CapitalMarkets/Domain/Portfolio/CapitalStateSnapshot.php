<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class CapitalStateSnapshot
{
 public function __construct(
  public string $portfolioId,public DateTimeImmutable $timestamp,
  public Decimal $total,public Decimal $available,public Decimal $allocated,public Decimal $reserved,
  public Decimal $deployed,public Decimal $locked,public Decimal $margined,public Decimal $unsettled,
  public Decimal $minimumCashBuffer,public Decimal $emergencyHedgeBuffer,public Decimal $settlementBuffer,
  public array $byLocation=[]
 ){}
}
