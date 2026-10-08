<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$assert=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};
foreach(['app/Domains/CapitalMarkets/Application/Contract/CapitalRiskRepositoryInterface.php','app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/MysqlCapitalRiskRepository.php','app/migrations/20261008_000134_capital_markets_capital_risk.sql'] as $f)$assert(is_file($root.'/'.$f),'Missing '.$f);
$m=(string)file_get_contents($root.'/app/migrations/20261008_000134_capital_markets_capital_risk.sql');
foreach(['tn_capital_market_exposure_snapshots','tn_capital_market_portfolio_risk_snapshots','tn_capital_market_risk_envelopes','tn_capital_market_allocation_policies','tn_capital_market_allocation_plans','uq_cm_allocation_fingerprint','tn_capital_market_strategy_allocations','tn_capital_market_rebalance_plans','tn_capital_market_correlation_snapshots','tn_capital_market_stress_results'] as $n)$assert(str_contains($m,$n),'Migration missing '.$n);
echo "Capital Markets Capital Risk persistence contracts passed.\n";
