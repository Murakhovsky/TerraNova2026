<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Risk\MarginSnapshot;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class MarginAggregationEngine
{
 /** @param list<array<string,mixed>> $positions */
 public function aggregate(array $positions):MarginSnapshot
 {
  $initial=Decimal::fromString('0');$maintenance=Decimal::fromString('0');$available=Decimal::fromString('0');$venues=[];
  foreach($positions as $p){
   $venue=(string)($p['venue_id']??$p['venue']??'UNKNOWN');
   $im=Decimal::fromString((string)($p['initial_margin']??'0'));$mm=Decimal::fromString((string)($p['maintenance_margin']??'0'));$am=Decimal::fromString((string)($p['available_margin']??'0'));
   $initial=DecimalMath::add($initial,$im);$maintenance=DecimalMath::add($maintenance,$mm);$available=DecimalMath::add($available,$am);
   if(!isset($venues[$venue]))$venues[$venue]=['initial_margin'=>'0','maintenance_margin'=>'0','available_margin'=>'0'];
   $venues[$venue]['initial_margin']=DecimalMath::add(Decimal::fromString($venues[$venue]['initial_margin']),$im)->value();
   $venues[$venue]['maintenance_margin']=DecimalMath::add(Decimal::fromString($venues[$venue]['maintenance_margin']),$mm)->value();
   $venues[$venue]['available_margin']=DecimalMath::add(Decimal::fromString($venues[$venue]['available_margin']),$am)->value();
  }
  $base=DecimalMath::add($initial,$available);
  $utilization=$base->isZero()?Decimal::fromString('0'):DecimalMath::divide($initial,$base);
  ksort($venues);
  return new MarginSnapshot($initial,$maintenance,$available,$utilization,$venues);
 }
}
