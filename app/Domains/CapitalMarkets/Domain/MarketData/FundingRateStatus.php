<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\MarketData;
enum FundingRateStatus:string
{
    case Estimated='ESTIMATED';
    case Current='CURRENT';
    case Settled='SETTLED';
    case Unknown='UNKNOWN';
    public function validForDecision():bool{return $this!==self::Unknown;}
}
