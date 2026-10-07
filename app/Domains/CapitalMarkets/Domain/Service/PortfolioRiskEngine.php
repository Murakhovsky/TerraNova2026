<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioRiskState;
use Domains\CapitalMarkets\Domain\Risk\RiskEnvelope;
use Domains\CapitalMarkets\Domain\Risk\RiskHeadroom;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final class PortfolioRiskEngine {
 /** @param array<string,Decimal|string|int|float> $metrics */
 public function assess(RiskEnvelope $envelope,array $metrics,PortfolioRiskState $currentState=PortfolioRiskState::Normal):array {
  $state=$currentState; $headroom=[]; $breaches=[]; $warnings=[];
  foreach($envelope->limits as $limit){
   $current=$metrics[$limit->metric]??Decimal::fromString('0');
   if(!$current instanceof Decimal)$current=Decimal::fromString((string)$current);
   $h=RiskHeadroom::fromLimit($limit,$current);
   $key=$limit->metric.':'.$limit->level->value.':'.($limit->scopeId??'*'); $headroom[$key]=$h;
   if($h->breached){
    if($limit->hard){$breaches[]=$key;$state=PortfolioRiskState::Restricted;}
    else{$warnings[]=$key;if($state===PortfolioRiskState::Normal)$state=PortfolioRiskState::Caution;}
   }
  }
  return ['state'=>$state,'headroom'=>$headroom,'breaches'=>$breaches,'warnings'=>$warnings];
 }
}
