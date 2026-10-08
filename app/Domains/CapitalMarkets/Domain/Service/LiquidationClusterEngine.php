<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class LiquidationClusterEngine
{
 /** @param list<array<string,mixed>> $positions */
 public function detect(array $positions,Decimal $maximumDistanceGap):array
 {
  $rows=[];
  foreach($positions as $p){
   if(!isset($p['mark_price'],$p['estimated_liquidation_price']))continue;
   $mark=Decimal::fromString((string)$p['mark_price']);$liq=Decimal::fromString((string)$p['estimated_liquidation_price']);
   if(!$mark->isPositive())continue;
   $distance=DecimalMath::divide(DecimalMath::abs(DecimalMath::subtract($mark,$liq)),$mark,12);
   $rows[]=['position_id'=>(string)($p['position_id']??''),'venue_id'=>(string)($p['venue_id']??''),'distance'=>$distance];
  }
  usort($rows,static fn(array $a,array $b):int=>$a['distance']->compareTo($b['distance']));
  $clusters=[];$current=[];
  foreach($rows as $row){
   if($current===[]){$current[]=$row;continue;}
   $last=$current[count($current)-1];
   $gap=DecimalMath::abs(DecimalMath::subtract($row['distance'],$last['distance']));
   if($gap->compareTo($maximumDistanceGap)<=0)$current[]=$row;
   else{if(count($current)>1)$clusters[]=$this->serialize($current);$current=[$row];}
  }
  if(count($current)>1)$clusters[]=$this->serialize($current);
  return $clusters;
 }
 private function serialize(array $rows):array{return ['positions'=>array_map(fn($r)=>$r['position_id'],$rows),'venues'=>array_values(array_unique(array_map(fn($r)=>$r['venue_id'],$rows))),'min_distance'=>$rows[0]['distance']->value(),'max_distance'=>$rows[count($rows)-1]['distance']->value()];}
}
