<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Risk\LiquidityBucket;
use Domains\CapitalMarkets\Domain\Risk\LiquidityBudget;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class LiquidityCapacityEngine {
 public function assess(Decimal $requested,Decimal $visibleDepth,Decimal $stressDepth,LiquidityBudget $budget,int $estimatedExitSeconds):array {
  $entryCapacity=$requested->compareTo($visibleDepth)<=0;$exitCapacity=$requested->compareTo($stressDepth)<=0&&$estimatedExitSeconds<=$budget->maximumExitSeconds;
  $bucket=match(true){$estimatedExitSeconds<60=>LiquidityBucket::L1,$estimatedExitSeconds<900=>LiquidityBucket::L2,$estimatedExitSeconds<3600=>LiquidityBucket::L3,default=>LiquidityBucket::L4};
  $stressShortfall=$requested->compareTo($stressDepth)>0?DecimalMath::subtract($requested,$stressDepth):Decimal::fromString('0');
  return ['can_enter'=>$entryCapacity,'can_exit_stress'=>$exitCapacity,'bucket'=>$bucket,'stress_shortfall'=>$stressShortfall];
 }
}
