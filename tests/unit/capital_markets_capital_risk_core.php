<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$assert=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};
$required=[
'app/Domains/CapitalMarkets/Domain/Portfolio/PortfolioType.php','app/Domains/CapitalMarkets/Domain/Portfolio/CapitalState.php','app/Domains/CapitalMarkets/Domain/Portfolio/PortfolioRiskState.php','app/Domains/CapitalMarkets/Domain/Portfolio/PortfolioExposureSnapshot.php',
'app/Domains/CapitalMarkets/Domain/Risk/RiskEnvelope.php','app/Domains/CapitalMarkets/Domain/Risk/RiskLimit.php','app/Domains/CapitalMarkets/Domain/Risk/RiskBudget.php',
'app/Domains/CapitalMarkets/Domain/Allocation/AllocationPolicy.php','app/Domains/CapitalMarkets/Domain/Allocation/AllocationPlan.php','app/Domains/CapitalMarkets/Domain/Service/EconomicExposureEngine.php','app/Domains/CapitalMarkets/Domain/Service/PortfolioRiskEngine.php','app/Domains/CapitalMarkets/Domain/Service/CapitalAllocationEngine.php','app/Domains/CapitalMarkets/Domain/Service/PortfolioStressEngine.php','app/Domains/CapitalMarkets/Domain/Service/CorrelationEngine.php'];
foreach($required as $f)$assert(is_file($root.'/'.$f),'Missing CM Capital Risk core file: '.$f);
$allocator=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/Service/CapitalAllocationEngine.php');
foreach(['Deterministic constrained allocation','allowsNewRisk','hardCaps',"hash('sha256'",'ACCEPT_REDUCED_SIZE'] as $n)$assert(str_contains($allocator,$n),'Allocator contract missing '.$n);
$exposure=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/Service/EconomicExposureEngine.php');
foreach(['UNKNOWN_EXPOSURE','relationship_valid','gross','byUnderlying'] as $n)$assert(str_contains($exposure,$n),'Exposure contract missing '.$n);
echo "Capital Markets Capital Allocation & Risk core contracts passed.\n";
