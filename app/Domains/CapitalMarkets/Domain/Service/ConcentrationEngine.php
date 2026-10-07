<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class ConcentrationEngine {
 /** @param array<string,Decimal|string|int|float> $exposures @return array<string,Decimal> */
 public function calculate(array $exposures,Decimal $equity):array {
  $out=[];
  foreach($exposures as $key=>$value){
   $v=$value instanceof Decimal?$value:Decimal::fromString((string)$value);
   $abs=$v->isNegative()?DecimalMath::negate($v):$v;
   $out[(string)$key]=$equity->isZero()?Decimal::fromString('0'):DecimalMath::divide($abs,$equity);
  }
  ksort($out); return $out;
 }
}
