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
    'OperationsSectionReader $operations',
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
    "'PAPER'",
    "'RESEARCH'",
    "'STALE'",
    "'UNAVAILABLE'",
] as $needle){
    $assert(str_contains($readModel,$needle),'Decision Workspace read-model contract missing: '.$needle);
}
$assert(!preg_match('/\bfloat\b|\(float\)|floatval\s*\(/i',$readModel),'Decision Workspace read model must not introduce floating-point finance semantics.');

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
    'Portfolio Command Center',
    'Allocation Workspace',
    'Execution Cockpit',
    'Risk Center',
    'Performance Center',
    'Agent Center',
    'Data Quality Center',
    'PARTIAL DATA',
    'Time-window P&L not fabricated',
    'WHAT → WHY → MONEY → RISK → ACTION',
] as $needle){
    $assert(str_contains($template,$needle),'Decision Workspace UI contract missing: '.$needle);
}
$assert(!str_contains($template,'Execute Live'),'Live execution action must not be rendered.');
$assert(str_contains($template,'table-responsive'),'Large Decision Workspace tables must remain usable on narrow screens.');
$assert(str_contains($template,'aria-label'),'Critical Decision Workspace surfaces need accessible labels.');

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
] as $needle){
    $assert(str_contains($provider,$needle),'Primary navigation contract missing: '.$needle);
}

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
    'states: [normal, empty, partial, stale, error, permission_denied]',
] as $needle){
    $assert(str_contains($pages,$needle),'Decision Workspace page contract missing: '.$needle);
}

echo "CM-DECISION-WORKSPACE acceptance contracts passed.\n";
