<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Rebalance\RebalancePlan;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final class PortfolioRebalanceEngine {
 public function propose(string $portfolioId,array $current,array $target,Decimal $cost,int $riskImprovementPct,Decimal $returnImpact,float $threshold=0.05):RebalancePlan {
  $actions=[];$magnitude=0.0;
  foreach(array_unique(array_merge(array_keys($current),array_keys($target))) as $k){
   $from=(float)($current[$k]??0);$to=(float)($target[$k]??0);$d=$to-$from;$magnitude+=abs($d);
   if(abs($d)<$threshold)continue;$actions[]=['strategy'=>$k,'action'=>$d>0?'INCREASE':'REDUCE','delta'=>$d];
  }
  $decision=$actions===[]?'HOLD':'REBALANCE';
  $reason=$actions===[]?'Allocation differences are below hysteresis threshold.':'Target allocation materially improves portfolio mix.';
  return new RebalancePlan('reb-'.substr(hash('sha256',$portfolioId.json_encode([$current,$target])),0,16),$portfolioId,$current,$target,$actions,$cost,$riskImprovementPct,$returnImpact,$decision,$reason);
 }
}
