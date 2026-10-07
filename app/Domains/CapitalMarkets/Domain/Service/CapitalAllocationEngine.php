<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Allocation\AllocationItem;
use Domains\CapitalMarkets\Domain\Allocation\AllocationPlan;
use Domains\CapitalMarkets\Domain\Allocation\AllocationPolicy;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioRiskState;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class CapitalAllocationEngine {
 /** @param list<array<string,mixed>> $opportunities */
 public function allocate(string $portfolioId,Decimal $availableCapital,array $opportunities,AllocationPolicy $policy,PortfolioRiskState $riskState=PortfolioRiskState::Normal,array $hardCaps=[]):AllocationPlan {
  $input=['portfolio'=>$portfolioId,'available'=>$availableCapital->value(),'opportunities'=>$opportunities,'policy'=>$policy->version,'risk'=>$riskState->value,'caps'=>$hardCaps];
  $fingerprint=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
  $remaining=$availableCapital; $ranked=[];
  foreach($opportunities as $o){$o['_score']=$this->score($o,$policy);$ranked[]=$o;}
  usort($ranked,static fn(array $a,array $b):int=>($b['_score']<=>$a['_score']) ?: strcmp((string)$a['opportunity_id'],(string)$b['opportunity_id']));
  $items=[]; $expected=Decimal::fromString('0'); $priority=1;
  foreach($ranked as $o){
   $requested=Decimal::fromString((string)$o['requested_capital']); $capacity=Decimal::fromString((string)$o['capacity']);
   $approved=$this->minimum($requested,$capacity,$remaining); $decision='ACCEPT'; $reason='Eligible under deterministic score and constraints.';
   if(!$riskState->allowsNewRisk()){$approved=Decimal::fromString('0');$decision='REJECT';$reason='Portfolio state '.$riskState->value.' blocks new risk.';}
   $cap=$hardCaps[(string)$o['opportunity_id']]??null; if($cap!==null)$approved=$this->minimum($approved,Decimal::fromString((string)$cap));
   if($approved->isZero()&&$decision!=='REJECT'){$decision='REJECT';$reason='No available capital or capacity.';}
   elseif(DecimalMath::compare($approved,$requested)<0){$decision='ACCEPT_REDUCED_SIZE';$reason='Reduced by capacity, hard headroom or available capital.';}
   if(!$approved->isZero()){$remaining=DecimalMath::subtract($remaining,$approved);$expected=DecimalMath::add($expected,DecimalMath::multiply($approved,Decimal::fromString((string)$o['expected_net_return'])));}
   $items[]=new AllocationItem((string)$o['strategy_version_id'],(string)$o['opportunity_id'],$requested,$approved,$priority++,Decimal::fromString((string)$o['expected_net_return']),Decimal::fromString((string)($o['expected_value']??'0')),$capacity,$decision,$reason,(array)($o['risk_budget']??[]));
  }
  return new AllocationPlan('alloc-'.substr($fingerprint,0,20),$portfolioId,new DateTimeImmutable(),$availableCapital,$items,[],$expected,0,100,Decimal::fromString('0'),$hardCaps,'Deterministic constrained allocation; capital cannot be oversubscribed.','PROPOSED',$policy->version,$fingerprint);
 }
 private function score(array $o,AllocationPolicy $p):float {
  $ret=(float)$o['expected_net_return'];$confidence=(float)$o['confidence'];$execution=(float)$o['execution_probability'];$risk=max(0.000001,(float)$o['risk']);$strategy=max(0.0,min(100.0,(float)$o['strategy_score']))/100;
  $concentration=max(0.0,min(1.0,(float)$o['concentration_penalty']));$liquidity=max(0.0,min(1.0,(float)$o['liquidity_penalty']));
  return $ret*$confidence*$execution*$strategy*(1-$concentration)*(1-$liquidity)/$risk*(float)($p->weights['score_multiplier']??1.0);
 }
 private function minimum(Decimal ...$values):Decimal{$m=$values[0];foreach($values as $v)if(DecimalMath::compare($v,$m)<0)$m=$v;return $m;}
}
