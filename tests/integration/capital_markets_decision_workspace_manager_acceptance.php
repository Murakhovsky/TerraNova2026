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
    "'comparison_state' => 'NOT COMPARABLE'",
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

$assert(!str_contains($template,'Execute Live'),'Manager Acceptance: Live execution must remain disabled and absent.');
$assert(str_contains($browser,"viewport: { width: 390, height: 844 }"),'Manager Acceptance: mobile critical workflow QA missing.');
$assert(str_contains($browser,'Version Comparison'),'Manager Acceptance: strategy version browser QA missing.');
$assert(str_contains($browser,'Relationship Graph'),'Manager Acceptance: relationship detail browser QA missing.');
$assert(str_contains($browser,'keyboard reachable'),'Manager Acceptance: keyboard accessibility QA missing.');

echo "CM-DECISION-WORKSPACE Manager Acceptance passed.\n";
