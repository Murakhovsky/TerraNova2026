<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
    '/capital-markets',
    '/capital-markets/opportunities',
    '/capital-markets/markets',
    '/capital-markets/research',
    '/capital-markets/strategies',
    '/capital-markets/portfolio',
    '/capital-markets/allocation',
    '/capital-markets/execution',
    '/capital-markets/risk',
    '/capital-markets/performance',
    '/capital-markets/agents',
    '/capital-markets/data-quality',
    '/capital-markets/research/hypotheses/{id}',
    '/capital-markets/strategies/{id}',
    '/capital-markets/execution/{id}',
    '/capital-markets/export/{dataset}.{format}',
] as $path){
    $assert(str_contains($routes,$path),'Decision Workspace route missing: '.$path);
}
foreach([
    'DecisionWorkspacePageController::overview',
    'DecisionWorkspacePageController::opportunities',
    'DecisionWorkspacePageController::markets',
    'DecisionWorkspacePageController::research',
    'DecisionWorkspacePageController::strategies',
    'DecisionWorkspacePageController::portfolio',
    'DecisionWorkspacePageController::allocation',
    'DecisionWorkspacePageController::execution',
    'DecisionWorkspacePageController::risk',
    'DecisionWorkspacePageController::performance',
    'DecisionWorkspacePageController::agents',
    'DecisionWorkspacePageController::dataQuality',
] as $controller){
    $assert(str_contains($routes,$controller),'Decision Workspace controller route missing: '.$controller);
}

$readModel=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/DecisionWorkspaceReadService.php');
foreach([
    'final readonly class DecisionWorkspaceReadService',
    'CapitalRiskService $capitalRisk',
    'ResearchLabService $research',
    'MarketDataAdministrationService $marketData',
    'CapitalMarketsTradingRepositoryInterface $trading',
    'AgentRunReadModelInterface $agentRuns',
    'ActivityHistoryRepositoryInterface $activityHistory',
    'public function searchEntities(',
    'public function overview(',
    'public function opportunities(',
    'public function opportunity(',
    'public function markets(',
    'public function research(',
    'public function strategies(',
    'public function portfolio(',
    'public function allocation(',
    'public function execution(',
    'public function risk(',
    'public function performance(',
    'public function agents(',
    'public function dataQuality(',
    "'today_net_pnl' => null",
    "'pnl_30d' => null",
    "'live_enabled' => false",
    "'research_decision'",
    "'simulation_opportunities'",
    "'KEEP TESTING'",
    "'PARTIALLY_HEDGED'",
    "'RECOVERY_REQUIRED'",
    "'PAPER'",
    "'RESEARCH'",
    "'STALE'",
    "'UNAVAILABLE'",
    "'economics_status'",
    "'economics_coverage'",
    "'costs_by_type'",
    "'quality_rows'",
    'private function qualityRows(',
    "'book_age_ms' => null",
    "'reference_age_ms'",
    "'reference_trust'",
    "'book_age_note'",
] as $needle){
    $assert(str_contains($readModel,$needle),'Decision Workspace read-model contract missing: '.$needle);
}
$assert(!preg_match('/\bfloat\b|\(float\)|floatval\s*\(/i',$readModel),'Decision Workspace read model must not introduce floating-point finance semantics.');

$capitalRisk=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/CapitalRiskService.php');
foreach([
    'realizedExecutionEconomics',
    "'realized_gross_pnl'",
    "'realized_costs'",
    "'realized_net_pnl'",
    "'economics_coverage'",
    "'economics_status'",
    "'costs_by_type'",
    "['COMPLETED','COMPLETED_COMPENSATED','CLOSED']",
    'Slippage is already embedded in executable fill prices',
] as $needle){
    $assert(str_contains($capitalRisk,$needle),'Capital Markets canonical performance economics contract missing: '.$needle);
}
$assert(!preg_match('/\(float\)|floatval\s*\(/i',$capitalRisk),'Capital Markets performance economics must not use floating-point money arithmetic.');

$template=(string)file_get_contents($root.'/symfony/templates/experience/capital_markets/decision_workspace.html.twig');
foreach([
    'Decision Workspace',
    'MODE:',
    'LIVE · DISABLED',
    'Portfolio Equity',
    'Available Capital',
    'Net P&L',
    'Risk',
    'Data',
    'Recommended Actions',
    'Opportunity Board',
    'Expected Net',
    'Portfolio Impact',
    'Economics Waterfall',
    'Decision Trace',
    'Market Explorer',
    'Research Pipeline',
    'Strategy Lab',
    'Capital Map',
    'Recommended Allocation',
    'Execution Groups',
    'Limits / Headroom',
    'P&L Attribution ·',
    'Gross → Costs → Net',
    'Realized Gross P&L',
    'Realized Costs',
    'Realized Net P&L',
    'Economics Coverage',
    'Cost Breakdown',
    'Agent Authority',
    'Market Quality',
    'Quote / Book / Reference freshness',
    'Quote Age',
    'Book Age',
    'Reference Age',
    'Why Untrusted?',
    'independent age unavailable',
    'PARTIAL DATA',
    'Time-window P&L not fabricated',
    'permissions.opportunity_view',
    'permissions.portfolio_view',
    'permissions.market_data_quality_view',
    'Portfolio simulation is hidden because this role does not have Portfolio View authority.',
    'Portfolio-aware detail unavailable to this role.',
    'No currently authorized action requires attention.',
    'Results',
    'Decision:',
    'Simulate portfolio impact',
    'Transport:',
    'HIGH RISK ·',
    'Residual Unhedged',
    'WHAT → WHY → MONEY → RISK → ACTION',
] as $needle){
    $assert(str_contains($template,$needle),'Decision Workspace UI contract missing: '.$needle);
}
$assert(!str_contains($template,'Execute Live'),'Live execution action must not be rendered.');
$assert(str_contains($template,'table-responsive'),'Large Decision Workspace tables must remain usable on narrow screens.');
$assert(str_contains($template,'aria-label'),'Critical Decision Workspace surfaces need accessible labels.');

$decisionTraceComponent=(string)file_get_contents($root.'/symfony/src/Web/Experience/Component/CosDecisionTrace.php');
$decisionTraceTemplate=(string)file_get_contents($root.'/symfony/templates/components/experience/cos_decision_trace.html.twig');
$uiCatalog=(string)file_get_contents($root.'/symfony/src/Web/Experience/Dev/UiCatalogRegistry.php');
foreach([
    "name: 'CosDecisionTrace'",
    "template: 'components/experience/cos_decision_trace.html.twig'",
] as $needle){
    $assert(str_contains($decisionTraceComponent,$needle),'Reusable Decision Trace component contract missing: '.$needle);
}
foreach(['aria-label="{{ label }}"','{% for step in steps %}','emptyCopy'] as $needle){
    $assert(str_contains($decisionTraceTemplate,$needle),'Reusable Decision Trace template contract missing: '.$needle);
}
$assert(str_contains($uiCatalog,"\$this->entry('CosDecisionTrace'"),'CosDecisionTrace must be registered in the canonical UI Catalog.');
$assert(substr_count($template,'<twig:CosDecisionTrace')>=2,'Opportunity and Hypothesis views must reuse the canonical Decision Trace component.');

$provider=(string)file_get_contents($root.'/symfony/src/Web/Experience/Extension/Provider/CapitalMarketsWebProvider.php');
foreach([
    "'Overview','/capital-markets'",
    "'Opportunities','/capital-markets/opportunities'",
    "'Markets','/capital-markets/markets'",
    "'Research','/capital-markets/research'",
    "'Strategies','/capital-markets/strategies'",
    "'Portfolio','/capital-markets/portfolio'",
    "'Allocation','/capital-markets/allocation'",
    "'Execution','/capital-markets/execution'",
    "'Risk','/capital-markets/risk'",
    "'Performance','/capital-markets/performance'",
    "'Agents','/capital-markets/agents'",
    "'Data Quality','/capital-markets/data-quality'",
    'DecisionWorkspaceReadService $workspace',
    'searchEntities($context->organizationId)',
    'kind: $entity[\'kind\']',
] as $needle){
    $assert(str_contains($provider,$needle),'Primary navigation contract missing: '.$needle);
}

$controller=(string)file_get_contents($root.'/symfony/src/Web/CapitalMarkets/DecisionWorkspacePageController.php');
foreach([
    'public function export(',
    "'opportunities'",
    "'research-results'",
    "'executions'",
    "'performance'",
    "fputcsv(",
    "new JsonResponse(",
    "'portfolio_view' =>",
    "'opportunity_view' =>",
    "'market_data_quality_view' =>",
    '$this->tenants->current()',
    "new RedirectResponse('/auth/login')",
] as $needle){
    $assert(str_contains($controller,$needle),'Decision Workspace export contract missing: '.$needle);
}
$assert(!str_contains($controller,'->requireTenant()'),'Decision Workspace must use the canonical TenantContextProviderInterface::current() contract.');

$preferences=(string)file_get_contents($root.'/symfony/assets/controllers/capital_markets_workspace_controller.js');
foreach([
    'cos.capital_markets.table_density',
    'cos.capital_markets.columns.',
    'cos.capital_markets.column_order.',
    'localStorage',
    'toggleColumn',
    'moveColumn',
    'columnOrder',
    'changeDensity',
    'simulateOpportunity',
    '/api/v1/capital-markets/portfolio/simulate-opportunity',
    'X-CSRF-Token',
    'setInterval',
    'updateAge',
] as $needle){
    $assert(str_contains($preferences,$needle),'Decision Workspace presentation preference contract missing: '.$needle);
}
$assert(!preg_match('/\bprice\s*[+\-*\/]\s*|\bpnl\s*[+\-*\/]\s*|\brisk\s*[+\-*\/]\s*/i',$preferences),'Presentation controller must not calculate finance.');

$realtime=(string)file_get_contents($root.'/symfony/src/Web/CapitalMarkets/CapitalMarketsRealtimeEventConsumer.php');
foreach([
    'DurableEventConsumerInterface',
    "capital-markets.decision-workspace-realtime.v1",
    "capital_markets.market_state.",
    "capital_markets.portfolio.",
    "capital_markets.allocation.",
    "capital_markets.research.",
    "capital_markets.execution.",
    'workspace($event->organizationId, self::WORKSPACE_ID)',
] as $needle){
    $assert(str_contains($realtime,$needle),'Decision Workspace realtime contract missing: '.$needle);
}
$stream=(string)file_get_contents($root.'/symfony/templates/experience/realtime/streams/capital_markets_decision_signal.stream.html.twig');
$assert(str_contains($stream,'cos-capital-markets-signal'),'Decision Workspace realtime signal target missing.');

$browser=(string)file_get_contents($root.'/tests/browser/capital_markets_decision_workspace.mjs');
foreach([
    '/capital-markets/opportunities',
    '/capital-markets/allocation',
    '/capital-markets/data-quality',
    "viewport: { width: 390, height: 844 }",
    "LIVE · DISABLED",
    "Execute Live",
    "keyboard",
    "column order did not persist across reload",
] as $needle){
    $assert(str_contains($browser,$needle),'Decision Workspace browser QA contract missing: '.$needle);
}
$workflow=(string)file_get_contents($root.'/.github/workflows/runtime.yml');
$assert(str_contains($workflow,'tests/browser/capital_markets_decision_workspace.mjs'),'Decision Workspace browser QA must run in runtime CI.');

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach([
    'Domains\\CapitalMarkets\\Application\\Service\\DecisionWorkspaceReadService: ~',
    'App\\Web\\CapitalMarkets\\DecisionWorkspacePageController:',
] as $needle){
    $assert(str_contains($services,$needle),'Decision Workspace DI wiring missing: '.$needle);
}

$pages=(string)file_get_contents($root.'/resources/experience/pages/capital_markets/foundation.yaml');
foreach([
    'capital_markets.overview',
    'capital_markets.opportunities',
    'capital_markets.markets',
    'capital_markets.hypothesis',
    'capital_markets.strategies',
    'capital_markets.strategy',
    'capital_markets.execution',
    'capital_markets.execution.detail',
    'capital_markets.performance',
    'capital_markets.agents',
    'capital_markets.data_quality',
    'states: [normal, loading, empty, error, permission_denied]',
    'states: [normal, empty, error]',
] as $needle){
    $assert(str_contains($pages,$needle),'Decision Workspace page contract missing: '.$needle);
}

echo "CM-DECISION-WORKSPACE acceptance contracts passed.\n";
