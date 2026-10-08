<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Risk\RiskLimit;
final class RiskPolicyHierarchy
{
 /** @param list<RiskLimit> $limits @return list<RiskLimit> */
 public function mostRestrictive(array $limits):array
 {
  $resolved=[];
  foreach($limits as $limit){
   $key=$limit->metric.'|'.($limit->scopeId??'*');
   if(!isset($resolved[$key])||$limit->limit->compareTo($resolved[$key]->limit)<0)$resolved[$key]=$limit;
   elseif($limit->limit->compareTo($resolved[$key]->limit)===0&&$limit->hard&&!$resolved[$key]->hard)$resolved[$key]=$limit;
  }
  ksort($resolved);return array_values($resolved);
 }
}
