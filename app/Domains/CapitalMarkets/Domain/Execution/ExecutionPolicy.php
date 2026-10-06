<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Execution;
enum ExecutionPolicy:string
{
    case Simultaneous='SIMULTANEOUS';
    case LiquidityFirst='LIQUIDITY_FIRST';
    case RiskFirst='RISK_FIRST';
    case Sequential='SEQUENTIAL';
}
