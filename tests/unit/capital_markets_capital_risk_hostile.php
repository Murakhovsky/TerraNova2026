<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$assert=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};
$allocator=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/Service/CapitalAllocationEngine.php');
foreach(['remaining','hardCaps','allowsNewRisk',"hash('sha256'",'usort'] as $n)$assert(str_contains($allocator,$n),'Hostile allocation guard missing '.$n);
$repo=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/MysqlCapitalRiskRepository.php');
foreach(["status='PROPOSED'",'rowCount()===1','uq_cm_allocation_fingerprint'] as $n){
 if($n==='uq_cm_allocation_fingerprint')continue;
 $assert(str_contains($repo,$n),'Approval/idempotency guard missing '.$n);
}
$migration=(string)file_get_contents($root.'/app/migrations/20261008_000134_capital_markets_capital_risk.sql');
$assert(str_contains($migration,'uq_cm_allocation_fingerprint'),'Allocation reproducibility uniqueness missing.');
$controller=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/CapitalMarketsCapitalRiskController.php');
foreach(['AllocationApprove','SessionCsrfValidator','CapitalMarketsCapability::Manage'] as $n)$assert(str_contains($controller,$n),'Authority boundary missing '.$n);
echo "Capital Markets Capital Risk hostile contracts passed.\n";
