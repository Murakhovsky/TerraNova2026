<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Stress\PortfolioStressResult;
use Domains\CapitalMarkets\Domain\Stress\PortfolioStressScenario;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class PortfolioStressEngine {
 /** @param list<array{position_id:string,asset?:string,venue?:string,notional:string|int}> $positions */
 public function run(PortfolioStressScenario $scenario,Decimal $capital,array $positions):PortfolioStressResult {
  $loss=Decimal::fromString('0');$affected=[];$venues=[];
  foreach($positions as $p){
   $shock=Decimal::fromString((string)($scenario->shocks[(string)($p['asset']??'')]??'0'));
   if(isset($scenario->shocks['ALL']))$shock=DecimalMath::add($shock,Decimal::fromString((string)$scenario->shocks['ALL']));
   $venue=(string)($p['venue']??'');
   if($venue!==''&&(($scenario->shocks['VENUE:'.$venue]??null)==='OFFLINE')){$venues[$venue]=true;$shock=Decimal::fromString('-1');}
   if($shock->isZero())continue;
   $notional=Decimal::fromString((string)$p['notional']);
   $impact=DecimalMath::multiply(DecimalMath::abs($notional),DecimalMath::abs($shock));
   $loss=DecimalMath::add($loss,$impact);$affected[]=(string)$p['position_id'];
  }
  $remaining=DecimalMath::subtract($capital,$loss);if($remaining->isNegative())$remaining=Decimal::fromString('0');
  return new PortfolioStressResult($scenario->id,$loss,Decimal::fromString('0'),$affected,array_keys($venues),[],$remaining);
 }
}
