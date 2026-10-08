<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$template=(string)file_get_contents($root.'/symfony/templates/experience/capital_markets/decision_workspace.html.twig');
$read=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/DecisionWorkspaceReadService.php');
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
$browser=(string)file_get_contents($root.'/tests/browser/capital_markets_decision_workspace.mjs');

$managerQuestions=[
    'capital'=>['Portfolio Equity','Available Capital','Capital Map'],
    'profit'=>['Today Net P&L','Net P&L','Gross → Costs → Net'],
    'risk'=>['Risk State','Top Risks','Limits / Headroom'],
    'opportunity'=>['Top Opportunities','Opportunity Board','Expected Net'],
    'portfolio_fit'=>['Portfolio Impact','Approved Capital'],
    'action'=>['Recommended Actions','Scenario Simulator'],
    'data_trust'=>['Market Quality','Why Untrusted?','PARTIAL DATA'],
    'execution'=>['Execution Groups','Residual Unhedged','HIGH RISK ·'],
];
foreach($managerQuestions as $question=>$needles){
    foreach($needles as $needle){
        $assert(str_contains($template,$needle),'Manager Acceptance ['.$question.'] missing UI evidence: '.$needle);
    }
}

foreach([
    '/capital-markets',
    '/capital-markets/opportunities',
    '/capital-markets/markets',
    '/capital-markets/markets/{id}',
    '/capital-markets/relationships/{id}',
    '/capital-markets/research',
    '/capital-markets/strategies',
    '/capital-markets/portfolio',
    '/capital-markets/allocation',
    '/capital-markets/execution',
    '/capital-markets/risk',
    '/capital-markets/performance',
    '/capital-markets/agents',
    '/capital-markets/data-quality',
] as $path){
    $assert(str_contains($routes,$path),'Manager Acceptance route missing: '.$path);
}

foreach([
    "'today_net_pnl' => null",
    "'pnl_30d' => null",
    "'live_enabled' => false",
    "'NOT COMPARABLE'",
    '$sourceId !== $targetId',
    '$sourceCurrency === $targetCurrency',
    'count(array_filter($sourceRows, $trustedQuote)) > 0',
    'count(array_filter($targetRows, $trustedQuote)) > 0',
    "'version_comparison'",
    "'PARTIALLY_HEDGED'",
    "'STALE'",
    "'UNAVAILABLE'",
] as $needle){
    $assert(str_contains($read,$needle),'Manager Acceptance read-model safety evidence missing: '.$needle);
}

foreach([
    'LIVE · DISABLED',
    'Time-window P&L not fabricated',
    'NOT COMPARABLE',
    'Version Comparison',
    'PARTIAL DATA',
    'Application health ≠ Market data health',
    'WHAT → WHY → MONEY → RISK → ACTION',
] as $needle){
    $assert(str_contains($template,$needle),'Manager Acceptance UX safety evidence missing: '.$needle);
}

$controller=(string)file_get_contents($root.'/symfony/src/Web/CapitalMarkets/DecisionWorkspacePageController.php');
foreach([
    'CanonicalMarketEventRepositoryInterface',
    'MarketDataHistoryView',
    "'history_state' =",
    "'source_instrument']['id']",
    "'target_instrument']['id']",
    "'quote_asset' =>",
    "in_array(\$sourceCurrency, \$commonQuotes, true)",
] as $needle) {
    $haystack = str_contains($needle, 'MarketDataHistoryView') ? $controller : $read;
    if ($needle === "'history_state' =") {
        $needle = "'history_state'] =";
    }
    $assert(str_contains($haystack,$needle),'Historical/relationship data boundary missing: '.$needle);
}
$assert(str_contains($controller,"CapitalMarketsCapability::RelationshipView"),'Market Explorer must check Relationship View authority.');
$assert(str_contains($controller,"'comparison_state'] = 'RESTRICTED'"),'Relationship Detail must redact market evidence without Market Data View authority.');
$assert(str_contains($template,"permissions.relationship_view"),'Relationship UI must hide restricted instrument relationships.');
$assert(str_contains($template,"permissions.market_data_view"),'Relationship price comparison must require Market Data View.');
$assert(str_contains($controller,'market_data_history_view'),'Historic-market view permission must be exposed to the Twig screen.');
$assert(is_file($root.'/app/Domains/CapitalMarkets/Application/Service/HistoricalRelationshipBasisProjector.php'),'Canonical historical Basis projector missing.');
$assert(is_file($root.'/app/Domains/CapitalMarkets/Application/Service/PortfolioNavWindowProjector.php'),'NAV P&L projector missing.');
$assert(is_file($root.'/app/Domains/CapitalMarkets/Application/Service/PortfolioNavSnapshotProducer.php'),'Guarded NAV producer is missing.');
$assert(is_file($root.'/app/Domains/CapitalMarkets/Application/Service/PortfolioNavSpotMarkEvidence.php'),'Strict spot asset mark evidence service must exist.');
$spot=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/PortfolioNavSpotMarkEvidence.php');
$assert(str_contains($spot,'DERIVATIVE_OR_UNCLASSIFIED_POSITION_REQUIRES_MARGIN_ACCOUNTING'),'NAV must reject unclassified perpetual and derivative valuation as cash inventory.');
$assert(str_contains($spot,"'mark_reconciled'=>false"),'Spot market value candidate must never assert independent reconciliation.');

$collector=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/PortfolioNavEvidenceCollector.php');
$assert(str_contains($collector,'EXTERNAL_FLOW_LEDGER_UNAVAILABLE'),'NAV collector must block absent certified flow ledger.');
$assert(str_contains($collector,'POSITION_MARK_UNTRUSTED'),'NAV collector must reject untrusted market marks.');
$assert(str_contains($collector,'POSITION_MARK_STALE_OR_CLOCK_UNCERTAIN'),'NAV collector must enforce source timestamp freshness.');
$assert(str_contains($collector,'BALANCE_NEGATIVE_AMOUNT'),'NAV collector must validate venue balance amounts.');
$assert(str_contains($collector,'PortfolioLedgerIntegrityAudit::inspect'),'NAV collector must independently check trading ledger integrity.');
$assert(str_contains($collector,'PortfolioNavStatementReconciliationPreview::inspect'),'NAV collector must publish tenant-scoped source-versus-paper statement differences.');
$assert(is_file($root.'/app/Domains/CapitalMarkets/Application/Service/PortfolioNavStatementReconciliationPreview.php'),'NAV statement reconciliation preview missing.');
$assert(str_contains($template,'Unreconciled venue cash statement differences'),'Performance must show venue statement mismatches rather than silently certifying cash.');

$assert(str_contains($read,'nav_preflight'),'Performance read model must surface NAV reconciliation diagnostics.');
$assert(str_contains($template,'NAV source integrity &amp; reconciliation'),'Performance must disclose accounting blockers.');
$assert(str_contains($collector,"'snapshot_written'=>false"),'NAV preflight must not claim successful persistence.');
$command=(string)file_get_contents($root.'/symfony/src/Command/CapitalMarketsNavCollectCommand.php');
$assert(str_contains($command,'cos:capital-markets:nav:collect'),'NAV preflight command must be available to operators.');
$assert(str_contains($command,'Command::FAILURE'),'Incomplete NAV must produce a failing scheduler status.');
$sourceImport=(string)file_get_contents($root.'/symfony/src/Command/CapitalMarketsNavEvidenceImportCommand.php');
$assert(str_contains($sourceImport,'PortfolioNavFinancialEvidencePolicy::normalize'),'Financial source imports must validate original evidence.');
$assert(str_contains($sourceImport,'RECORDED_PENDING_RECONCILIATION'),'Import cannot grant reconciliation authority.');
$assert(str_contains($sourceImport,"'nav_snapshot_written'=>false"),'Operator source import must not create NAV snapshots.');
$assert(str_contains($template,'Unreconciled venue cash statement differences'),'UI must show source statement mismatches.');


$assert(str_contains((string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/PortfolioNavSnapshotProducer.php'),'marks_reconciled'),'NAV producer must block unverified position marks.');
$assert(is_file($root.'/app/migrations/20261008_000135_capital_markets_portfolio_valuation.sql'),'Canonical NAV snapshot migration missing.');
$assert(str_contains($read,'PortfolioValuationSnapshotRepositoryInterface'),'Decision read model must consume verified NAV repository.');
$assert(str_contains($read,'PortfolioNavWindowProjector::project'),'Today/30D Portfolio P&L must come from reconciled NAV projector.');
$assert(str_contains($controller,'MarketDataHistoryView'),'Relationship historical Basis must require history permission.');
$assert(str_contains($template,'Historical Basis · 7D'),'Relationship Detail must show historical Basis status.');
$assert(str_contains($template,'Portfolio NAV performance windows'),'Performance page must expose reconciled Portfolio NAV evidence.');
$assert(str_contains($template,'Portfolio NAV PnL evidence'),'Overview must expose valuation completeness.');
$assert(str_contains($template,'Historical Market Evidence · 7D'),'Historical canonical event list must be visible on Market Detail.');
$assert(str_contains($template,'permissions.market_data_history_view'),'Market historical evidence must remain permission-gated.');

$assert(!str_contains($template,'Execute Live'),'Manager Acceptance: Live execution must remain disabled and absent.');
$assert(str_contains($browser,"viewport: { width: 390, height: 844 }"),'Manager Acceptance: mobile critical workflow QA missing.');
$assert(str_contains($browser,'Version Comparison'),'Manager Acceptance: strategy version browser QA missing.');
$assert(str_contains($browser,'Relationship Graph'),'Manager Acceptance: relationship detail browser QA missing.');
$assert(str_contains($browser,'keyboard reachable'),'Manager Acceptance: keyboard accessibility QA missing.');

echo "CM-DECISION-WORKSPACE Manager Acceptance passed.\n";
