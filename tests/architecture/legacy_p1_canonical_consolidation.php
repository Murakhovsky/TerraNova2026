<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$need=[
 'symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainContextCompressor.php',
 'app/Domains/CapitalMarkets/Domain/Research/HistoricalBacktestPromotionPolicy.php',
 'app/Domains/CapitalMarkets/Application/Service/ResearchBacktestService.php',
 'app/Domains/CapitalMarkets/Application/Service/TokenizedEquityUniverseScanner.php',
 'app/Domains/CapitalMarkets/Application/Service/InstrumentPoolScheduleTargets.php',
 'symfony/src/Application/CapitalMarkets/Handler/RunInstrumentPoolSnapshotScansHandler.php'
];
foreach($need as $path){if(!is_file($root.'/'.$path))throw new RuntimeException('Legacy canonical owner missing: '.$path);}
foreach([
 'app/Domains/CapitalMarkets/Application/Service/TokenizedEquityHistoricalBacktestService.php',
 'app/Domains/CapitalMarkets/Application/Service/TokenizedEquityScannerService.php',
 'app/migrations/20261006_000130_capital_markets_tokenized_equity_backtest.sql',
 'app/migrations/20261006_000130_capital_markets_tokenized_equity_scanner.sql'
] as $path){if(is_file($root.'/'.$path))throw new RuntimeException('Old parallel execution/migration must not be restored: '.$path);}
$roles=(string)file_get_contents($root.'/symfony/src/Engineering/Domain/Agent/AgentRole.php');
foreach(['ENGINEERING_MANAGER','PRODUCT_REQUIREMENTS','QA_PLANNER','PRINCIPAL_ARCHITECT','SECURITY_SPECIALIST','DATABASE_MIGRATION_SPECIALIST','DEVOPS_SPECIALIST','INTEGRATION_RELEASE'] as $role){
 if(!str_contains($roles,$role))throw new RuntimeException('Canonical agent role missing: '.$role);
}
$owner=(string)file_get_contents($root.'/app/Infrastructure/Platform/Persistence/TableOwnership.php');
if(substr_count($owner,"'tn_capital_market_backtest_runs'")!==1)throw new RuntimeException('Backtest ownership must remain singular.');
echo "Legacy P1 execution authority consolidation PASS.\n";
