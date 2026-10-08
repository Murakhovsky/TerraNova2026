<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Portfolio\CapitalStateSnapshot;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class CapitalStateEngine
{
 public function snapshot(
  string $portfolioId,Decimal $total,Decimal $allocated,Decimal $reserved,Decimal $deployed,
  Decimal $locked,Decimal $margined,Decimal $unsettled,
  Decimal $minimumCashBuffer,Decimal $emergencyHedgeBuffer,Decimal $settlementBuffer,
  array $byLocation=[]
 ):CapitalStateSnapshot{
  $used=Decimal::fromString('0');
  foreach([$reserved,$deployed,$locked,$margined,$unsettled,$minimumCashBuffer,$emergencyHedgeBuffer,$settlementBuffer] as $component)$used=DecimalMath::add($used,$component);
  $available=DecimalMath::subtract($total,$used);if($available->isNegative())$available=Decimal::fromString('0');
  return new CapitalStateSnapshot($portfolioId,new DateTimeImmutable(),$total,$available,$allocated,$reserved,$deployed,$locked,$margined,$unsettled,$minimumCashBuffer,$emergencyHedgeBuffer,$settlementBuffer,$byLocation);
 }
}
