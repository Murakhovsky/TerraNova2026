<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
enum PortfolioRiskState:string {
 case Normal='NORMAL'; case Caution='CAUTION'; case Restricted='RESTRICTED'; case ReduceOnly='REDUCE_ONLY'; case Halted='HALTED'; case Emergency='EMERGENCY';
 public function allowsNewRisk():bool{return !in_array($this,[self::ReduceOnly,self::Halted,self::Emergency],true);}
}
