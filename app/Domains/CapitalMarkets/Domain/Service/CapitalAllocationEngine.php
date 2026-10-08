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
  $remaining=$availableCapital;$ranked=[];
  foreach($opportunities as $o){$o['_score']=$this->score($o,$policy);$ranked[]=$o;}
  usort($ranked,static function(array $a,array $b):int{$cmp=$b['_score']->compareTo($a['_score']);return $cmp!==0?$cmp:strcmp((string)$a['opportunity_id'],(string)$b['opportunity_id']);});
  $items=[];$expected=Decimal::fromString('0');$priority=1;
  foreach($ranked as $o){
   $requested=Decimal::fromString((string)$o['requested_capital']);$capacity=Decimal::fromString((string)$o['capacity']);
   $approved=$this->minimum($requested,$capacity,$remaining);$decision='ACCEPT';$reason='Eligible under deterministic score and constraints.';
   $blockedReason=trim((string)($o['blocked_reason']??''));
   if($blockedReason!==''){$approved=Decimal::fromString('0');$decision='REJECT';$reason=$blockedReason;}
   elseif(!$riskState->allowsNewRisk()){$approved=Decimal::fromString('0');$decision='REJECT';$reason='Portfolio state '.$riskState->value.' blocks new risk.';}
   $cap=$hardCaps[(string)$o['opportunity_id']]??null;if($cap!==null)$approved=$this->minimum($approved,Decimal::fromString((string)$cap));
   if($approved->isZero()&&$decision!=='REJECT'){$decision='REJECT';$reason='No available capital or capacity.';}
   elseif($decision!=='REJECT'&&$approved->compareTo($requested)<0){$decision='ACCEPT_REDUCED_SIZE';$reason='Reduced by capacity, hard headroom or available capital.';}
   if(!$approved->isZero()){$remaining=DecimalMath::subtract($remaining,$approved);$expected=DecimalMath::add($expected,DecimalMath::multiply($approved,Decimal::fromString((string)$o['expected_net_return'])));}
   $items[]=new AllocationItem((string)$o['strategy_version_id'],(string)$o['opportunity_id'],$requested,$approved,$priority++,Decimal::fromString((string)$o['expected_net_return']),Decimal::fromString((string)($o['expected_value']??'0')),$capacity,$decision,$reason,(array)($o['risk_budget']??[]));
  }
  return new AllocationPlan('alloc-'.substr($fingerprint,0,20),$portfolioId,new DateTimeImmutable(),$availableCapital,$items,[],$expected,0,100,Decimal::fromString('0'),$hardCaps,'Deterministic constrained allocation; capital cannot be oversubscribed.','PROPOSED',$policy->version,$fingerprint);
 }
 private function score(array $o,AllocationPolicy $p):Decimal {
  $ret=Decimal::fromString((string)$o['expected_net_return']);$confidence=Decimal::fromString((string)$o['confidence']);$execution=Decimal::fromString((string)$o['execution_probability']);
  $risk=Decimal::fromString((string)$o['risk']);if(!$risk->isPositive())$risk=Decimal::fromString('0.000001');
  $strategy=DecimalMath::divide(Decimal::fromString((string)$o['strategy_score']),Decimal::fromString('100'));
  $concentration=Decimal::fromString((string)$o['concentration_penalty']);$liquidity=Decimal::fromString((string)$o['liquidity_penalty']);
  $one=Decimal::fromString('1');$multiplier=Decimal::fromString((string)($p->weights['score_multiplier']??'1'));
  $numerator=DecimalMath::multiply($ret,$confidence);
  $numerator=DecimalMath::multiply($numerator,$execution);
  $numerator=DecimalMath::multiply($numerator,$strategy);
  $numerator=DecimalMath::multiply($numerator,DecimalMath::subtract($one,$concentration));
  $numerator=DecimalMath::multiply($numerator,DecimalMath::subtract($one,$liquidity));
  return DecimalMath::multiply(DecimalMath::divide($numerator,$risk),$multiplier);
 }
 private function minimum(Decimal ...$values):Decimal{$m=$values[0];foreach($values as $v)if($v->compareTo($m)<0)$m=$v;return $m;}
}
