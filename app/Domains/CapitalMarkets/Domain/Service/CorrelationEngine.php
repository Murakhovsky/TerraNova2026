<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class CorrelationEngine {
 /** @param array<string,list<string|int>> $series @return array<string,array<string,Decimal>> */
 public function matrix(array $series):array {
  ksort($series);$out=[];foreach($series as $a=>$xs){foreach($series as $b=>$ys)$out[$a][$b]=$this->pearson($xs,$ys);}return $out;
 }
 private function pearson(array $x,array $y):Decimal {
  $n=min(count($x),count($y));if($n<2)return Decimal::fromString('0');$x=array_slice($x,-$n);$y=array_slice($y,-$n);
  $sumX=Decimal::fromString('0');$sumY=Decimal::fromString('0');
  for($i=0;$i<$n;$i++){$sumX=DecimalMath::add($sumX,Decimal::fromString((string)$x[$i]));$sumY=DecimalMath::add($sumY,Decimal::fromString((string)$y[$i]));}
  $count=Decimal::fromString((string)$n);$mx=DecimalMath::divide($sumX,$count);$my=DecimalMath::divide($sumY,$count);
  $num=Decimal::fromString('0');$dx=Decimal::fromString('0');$dy=Decimal::fromString('0');
  for($i=0;$i<$n;$i++){
   $a=DecimalMath::subtract(Decimal::fromString((string)$x[$i]),$mx);$b=DecimalMath::subtract(Decimal::fromString((string)$y[$i]),$my);
   $num=DecimalMath::add($num,DecimalMath::multiply($a,$b));$dx=DecimalMath::add($dx,DecimalMath::multiply($a,$a));$dy=DecimalMath::add($dy,DecimalMath::multiply($b,$b));
  }
  if($dx->isZero()||$dy->isZero())return Decimal::fromString('0');
  $den=$this->sqrt(DecimalMath::multiply($dx,$dy));if($den->isZero())return Decimal::fromString('0');
  $value=DecimalMath::divide($num,$den,12);$one=Decimal::fromString('1');$minusOne=Decimal::fromString('-1');
  if($value->compareTo($one)>0)return $one;if($value->compareTo($minusOne)<0)return $minusOne;return $value;
 }
 private function sqrt(Decimal $value):Decimal {
  if($value->isZero())return $value;$guess=$value->compareTo(Decimal::fromString('1'))<0?Decimal::fromString('1'):$value;$two=Decimal::fromString('2');
  for($i=0;$i<24;$i++)$guess=DecimalMath::divide(DecimalMath::add($guess,DecimalMath::divide($value,$guess,18)),$two,18);
  return $guess;
 }
}
