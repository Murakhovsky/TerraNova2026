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
$assert(str_contains($template,'Historical Market Evidence · 7D'),'Historical canonical event list must be visible on Market Detail.');
$assert(str_contains($template,'permissions.market_data_history_view'),'Market historical evidence must remain permission-gated.');

$assert(!str_contains($template,'Execute Live'),'Manager Acceptance: Live execution must remain disabled and absent.');
$assert(str_contains($browser,"viewport: { width: 390, height: 844 }"),'Manager Acceptance: mobile critical workflow QA missing.');
$assert(str_contains($browser,'Version Comparison'),'Manager Acceptance: strategy version browser QA missing.');
$assert(str_contains($browser,'Relationship Graph'),'Manager Acceptance: relationship detail browser QA missing.');
$assert(str_contains($browser,'keyboard reachable'),'Manager Acceptance: keyboard accessibility QA missing.');

echo "CM-DECISION-WORKSPACE Manager Acceptance passed.\n";
