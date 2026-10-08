<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioExposureSnapshot;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class EconomicExposureEngine {
 /** @param list<array<string,mixed>> $positions */
 public function snapshot(string $portfolioId,array $positions,?DateTimeImmutable $at=null):PortfolioExposureSnapshot {
  $zero=Decimal::fromString('0');$gross=$zero;$net=$zero;
  $maps=['byUnderlying'=>[],'byAsset'=>[],'byVenue'=>[],'byStrategy'=>[],'byCurrency'=>[],'byCounterparty'=>[],'byChain'=>[],'byLiquidityBucket'=>[]];$unknown=[];
  foreach($positions as $p){
   $raw=Decimal::fromString((string)$p['notional']);$abs=DecimalMath::abs($raw);
   $signed=strtoupper((string)($p['side']??'LONG'))==='SHORT'?DecimalMath::negate($abs):$abs;
   if(array_key_exists('delta',$p))$signed=DecimalMath::multiply($signed,Decimal::fromString((string)$p['delta']));
   $gross=DecimalMath::add($gross,$abs);$valid=(bool)($p['relationship_valid']??true);$underlying=trim((string)($p['underlying_key']??''));
   if(!$valid||$underlying==='')$unknown[]=['instrument_id'=>(string)$p['instrument_id'],'exposure'=>$signed->value(),'reason'=>'UNKNOWN_EXPOSURE'];
   else{$maps['byUnderlying'][$underlying]=$this->add($maps['byUnderlying'][$underlying]??$zero,$signed);$net=DecimalMath::add($net,$signed);}
   foreach(['asset'=>'byAsset','venue'=>'byVenue','strategy'=>'byStrategy','currency'=>'byCurrency','counterparty'=>'byCounterparty','chain'=>'byChain','liquidity_bucket'=>'byLiquidityBucket'] as $src=>$dst){
    $k=trim((string)($p[$src]??''));if($k==='')continue;$maps[$dst][$k]=$this->add($maps[$dst][$k]??$zero,$signed);
   }
  }
  foreach($maps as &$m)ksort($m);unset($m);
  return new PortfolioExposureSnapshot($portfolioId,$at??new DateTimeImmutable(),$gross,$net,$maps['byUnderlying'],$maps['byAsset'],$maps['byVenue'],$maps['byStrategy'],$maps['byCurrency'],$maps['byCounterparty'],$maps['byChain'],$maps['byLiquidityBucket'],$unknown);
 }
 private function add(Decimal $a,Decimal $b):Decimal{return DecimalMath::add($a,$b);}
}
