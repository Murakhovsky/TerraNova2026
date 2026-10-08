<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Risk\DrawdownState;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class DrawdownEngine {
 public function drawdown(Decimal $equity,Decimal $peak):Decimal {
  if($peak->isZero())return Decimal::fromString('0');$loss=DecimalMath::subtract($peak,$equity);if($loss->isNegative())return Decimal::fromString('0');return DecimalMath::divide($loss,$peak);
 }
 public function state(Decimal $drawdown,array $thresholds):DrawdownState {
  foreach([['EMERGENCY',DrawdownState::Emergency],['STOP_NEW_RISK',DrawdownState::StopNewRisk],['REDUCED_RISK',DrawdownState::ReducedRisk],['CAUTION',DrawdownState::Caution]] as [$key,$state]){
   if(isset($thresholds[$key])&&$drawdown->compareTo(Decimal::fromString((string)$thresholds[$key]))>=0)return $state;
  }
  return DrawdownState::Normal;
 }
}
