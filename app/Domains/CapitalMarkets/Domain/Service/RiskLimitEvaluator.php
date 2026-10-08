<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Risk\RiskLimit;
use Domains\CapitalMarkets\Domain\Risk\RiskLimitType;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class RiskLimitEvaluator
{
 public function effective(RiskLimit $limit,Decimal $equity,Decimal $capital,?Decimal $dynamicMultiplier=null):RiskLimit
 {
  $value=match($limit->type){
   RiskLimitType::PercentOfEquity=>DecimalMath::multiply($equity,$limit->limit),
   RiskLimitType::PercentOfCapital=>DecimalMath::multiply($capital,$limit->limit),
   RiskLimitType::Dynamic=>DecimalMath::multiply($limit->limit,$dynamicMultiplier??Decimal::fromString('1')),
   default=>$limit->limit,
  };
  return new RiskLimit($limit->metric,$limit->level,$limit->type,$value,$limit->scopeId,$limit->hard||$limit->type===RiskLimitType::Hard);
 }
}
