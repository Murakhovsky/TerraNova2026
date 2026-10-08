<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$assert=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};
$service=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/CapitalRiskService.php');
foreach([
 'simulateOpportunityImpact','maximum_approved_capital','HARD_RISK_HEADROOM','deriveRiskHardCaps','INSUFFICIENT_AVAILABLE_CAPITAL','STALE_OR_UNTRUSTED_VALUATION',
 'INSUFFICIENT_LOCAL_CAPITAL','STRATEGY_NOT_LIVE_VALIDATED','minimum_cash_buffer',
 'emergency_hedge_buffer','settlement_buffer','approveAndReserve','proposeRebalance',
 'Rebalance costs exceed expected benefit.','refreshRisk','Risk envelope is not configured.'
] as $n)$assert(str_contains($service,$n),'Acceptance capability missing '.$n);
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
 '/capital-markets/portfolio','/capital-markets/risk','/capital-markets/allocation',
 '/api/v1/capital-markets/portfolio/simulate-opportunity','/api/v1/capital-markets/allocation/recalculate',
 '/api/v1/capital-markets/risk/stress','/api/v1/capital-markets/rebalance/plan','/api/v1/capital-markets/portfolio/agent'
] as $n)$assert(str_contains($routes,$n),'Capital Risk route missing '.$n);
echo "Capital Markets Capital Risk acceptance contracts passed.\n";
