<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
enum LiquidationRiskStatus:string
{
    case Safe='SAFE';
    case Warning='WARNING';
    case Breach='RISK_BREACH';
    case Unknown='UNKNOWN';
}
