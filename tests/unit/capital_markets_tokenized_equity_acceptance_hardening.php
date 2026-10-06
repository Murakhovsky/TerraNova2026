<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
    '/api/v1/capital-markets/tokenized-equities/executions',
    '/api/v1/capital-markets/tokenized-equities/positions',
    '/api/v1/capital-markets/tokenized-equities/ledger',
    '/api/v1/capital-markets/tokenized-equities/performance/tokenized-equity',
    '/api/v1/capital-markets/tokenized-equities/hypotheses/observations',
    '/api/v1/capital-markets/tokenized-equities/reconciliation',
] as $route){
    $assert(str_contains($routes,$route),'Missing Tokenized Equity read API route: '.$route);
}

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['TokenizedEquityTelemetry','TokenizedEquityReadService','PositionProjector'] as $service){
    $assert(str_contains($services,$service),'Missing Tokenized Equity acceptance service wiring: '.$service);
}

$repository=(string)file_get_contents(
    $root.'/app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/MysqlTokenizedEquityVerticalSliceRepository.php'
);
$assert(str_contains($repository,'INSERT IGNORE INTO tn_capital_market_paper_fills'),
    'Paper fill persistence must remain idempotent.');
$assert(str_contains($repository,'INSERT IGNORE INTO tn_capital_market_ledger_transactions'),
    'Ledger persistence must ignore duplicate idempotency keys safely.');
$assert(str_contains($repository,'available_capital=available_capital-:amount')
    &&str_contains($repository,'available_capital>=:amount'),
    'Capital reservation must be one guarded atomic update.');
$assert(str_contains($repository,'FOR UPDATE'),
    'Financial reservation/recovery paths must retain row locks.');
$assert(str_contains($repository,'listExecutions(')&&str_contains($repository,'listLedgerTransactions('),
    'Execution and ledger read models must be queryable.');

$execution=(string)file_get_contents(
    $root.'/app/Domains/CapitalMarkets/Application/Service/TokenizedEquityPaperExecutionService.php'
);
foreach([
    'persistBuyVenuePosition',
    'paper_execution_total',
    'paper_execution_realized_pnl',
    'paper_execution_edge_capture_ratio',
    'paper_execution_compensation_total',
    'CapitalMarketsAlertType::UnhedgedPosition',
] as $needle){
    $assert(str_contains($execution,$needle),'Paper execution acceptance contract missing: '.$needle);
}

$read=(string)file_get_contents(
    $root.'/app/Domains/CapitalMarkets/Application/Service/TokenizedEquityReadService.php'
);
foreach(['reconcile(','reconciliation_runs_total','PositionReconciliationError','performance('] as $needle){
    $assert(str_contains($read,$needle),'Read/reconciliation acceptance contract missing: '.$needle);
}

$universe=(string)file_get_contents(
    $root.'/app/Domains/CapitalMarkets/Application/Service/TokenizedEquityUniverseScanner.php'
);
foreach(['TokenizedEquityUniverse::fromArray','allowsHypothesis','allowsUnderlying','allowsToken','allowsVenue'] as $needle){
    $assert(str_contains($universe,$needle),'Curated universe scanner contract missing: '.$needle);
}
$assert(is_file($root.'/app/Domains/CapitalMarkets/Domain/Opportunity/TokenizedEquityUniverse.php'),
    'Configurable TokenizedEquityUniverse model is missing.');

$vertical=(string)file_get_contents(
    $root.'/app/Domains/CapitalMarkets/Application/Service/TokenizedEquityVerticalSliceService.php'
);
foreach(['spread_detector_runs_total','market_snapshots_total','opportunity_evaluations_total'] as $needle){
    $assert(str_contains($vertical,$needle),'Spread pipeline telemetry missing: '.$needle);
}

echo "Capital Markets VS1 acceptance hardening contracts passed.\n";
