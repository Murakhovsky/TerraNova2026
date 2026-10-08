<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$assert=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};
$agent=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Automation/Agent/CapitalMarketsPortfolioAgent.php');
foreach(['capital_markets_portfolio','maxActionsPerRun:0','Deterministic engines are authoritative','allocation.propose','risk.runstress','portfolio.simulateopportunityimpact'] as $n)$assert(str_contains($agent,$n),'Portfolio Agent contract missing '.$n);
foreach(['allocation.approve','risk.manage_policy','execute.live','ledger.mutate'] as $n)$assert(!str_contains($agent,$n),'Portfolio Agent must not expose forbidden authority '.$n);
$permission=(string)file_get_contents($root.'/symfony/src/Infrastructure/Automation/PortfolioAgentToolPermissionChecker.php');
foreach(['portfolio.getstate','portfolio.getexposure','portfolio.getrisk','portfolio.simulateallocation','portfolio.comparescenarios','strategy.getscorecard','performance.compare'] as $n)$assert(str_contains($permission,$n),'Portfolio Agent permission missing '.$n);
foreach(['approve','execute','manage_policy'])$assert(!str_contains(strtolower($permission),$n),'Forbidden Portfolio Agent permission leaked: '.$n);
$service=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/CapitalRiskService.php');
foreach(["proposal_actor_type']??'HUMAN'","'AGENT'","NO_SELF_APPROVAL","cm_alloc_res_","getCapitalReservation"] as $n)$assert(str_contains($service,$n),'Portfolio approval safety missing '.$n);
echo "Capital Markets Portfolio Agent authority contracts passed.\n";
