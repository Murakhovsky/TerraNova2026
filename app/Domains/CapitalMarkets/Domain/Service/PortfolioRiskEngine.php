<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioRiskState;
use Domains\CapitalMarkets\Domain\Risk\RiskEnvelope;
use Domains\CapitalMarkets\Domain\Risk\RiskHeadroom;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class PortfolioRiskEngine
{
 public function __construct(private RiskLimitEvaluator $limits,private RiskPolicyHierarchy $hierarchy){}
 /** @param array<string,Decimal|string|int> $metrics */
 public function assess(RiskEnvelope $envelope,array $metrics,PortfolioRiskState $currentState=PortfolioRiskState::Normal):array
 {
  $state=$currentState;$headroom=[];$breaches=[];$warnings=[];
  $equity=$this->decimal($metrics['equity']??'0');$capital=$this->decimal($metrics['capital']??$equity);
  $dynamic=$this->decimal($metrics['dynamic_limit_multiplier']??'1');
  $effective=[];
  foreach($envelope->limits as $limit)$effective[]=$this->limits->effective($limit,$equity,$capital,$dynamic);
  foreach($this->hierarchy->mostRestrictive($effective) as $limit){
   $metricKey=$limit->scopeId!==null?$limit->metric.':'.$limit->scopeId:$limit->metric;
   $current=$this->decimal($metrics[$metricKey]??$metrics[$limit->metric]??'0');
   $h=RiskHeadroom::fromLimit($limit,$current);
   $key=$limit->metric.':'.$limit->level->value.':'.($limit->scopeId??'*');$headroom[$key]=$h;
   if($h->breached){
    if($limit->hard){$breaches[]=$key;$state=PortfolioRiskState::Restricted;}
    else{$warnings[]=$key;if($state===PortfolioRiskState::Normal)$state=PortfolioRiskState::Caution;}
   }
  }
  return ['state'=>$state,'headroom'=>$headroom,'breaches'=>$breaches,'warnings'=>$warnings];
 }
 private function decimal(Decimal|string|int $value):Decimal{return $value instanceof Decimal?$value:Decimal::fromString((string)$value);}
}
