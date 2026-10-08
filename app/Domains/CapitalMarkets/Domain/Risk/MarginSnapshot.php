<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class MarginSnapshot
{
 public function __construct(
  public Decimal $initialMargin,public Decimal $maintenanceMargin,public Decimal $availableMargin,
  public Decimal $marginUtilization,public array $byVenue=[]
 ){}
}
