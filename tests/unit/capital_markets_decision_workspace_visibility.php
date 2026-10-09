<?php
declare(strict_types=1);

use App\Web\CapitalMarkets\DecisionWorkspaceVisibilityPolicy;

require dirname(__DIR__, 2).'/vendor/autoload.php';
// Root Composer autoload has no App\\ PSR-4 mapping; Symfony runtime does.
// Keep this isolated pure-PHP security test runnable in the root CI job.
require dirname(__DIR__, 2).'/symfony/src/Web/CapitalMarkets/DecisionWorkspaceVisibilityPolicy.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$source = [
    'global' => [
        'portfolio_equity'=>'120000', 'available_capital'=>'15000',
        'deployed_capital'=>'100000', 'reserved_capital'=>'5000',
        'net_pnl'=>'750', 'today_net_pnl'=>'110', 'pnl_30d'=>'320',
        'paper_equity'=>'10100','paper_nav_status'=>'SIMULATED',
        'paper_today_net_pnl'=>'10','paper_30d_net_pnl'=>'100',
        'portfolio_nav_windows'=>['today'=>['status'=>'COMPLETE','net_pnl'=>'110']],
        'risk_state'=>'HALTED', 'data_health'=>'STALE',
        'portfolio_updated_at'=>'2026-10-09T09:00:00+00:00',
        'market_updated_at'=>'2026-10-09T09:01:00+00:00',
        'last_updated'=>'2026-10-09T09:00:00+00:00',
        'alerts'=>[
            ['code'=>'RISK_STATE','severity'=>'CRITICAL','message'=>'HALTED'],
            ['code'=>'UNKNOWN_EXPOSURE','severity'=>'HIGH','message'=>'3 positions'],
            ['code'=>'DATA_HEALTH','severity'=>'WARNING','message'=>'STALE'],
        ],
        'critical_alerts'=>2,
    ],
    'paper_nav'=>['latest'=>['status'=>'SIMULATED','equity'=>'10100']],
    'capital'=>['total'=>'120000'], 'portfolio'=>['positions'=>[['id'=>'secret']]],
    'capital_map'=>[['key'=>'AVAILABLE','value'=>'15000']],
    'risk'=>['drawdown'=>'8.5'], 'material_risks'=>[['metric'=>'concentration']],
    'performance'=>['net_pnl'=>'750'], 'exposure'=>['unknown_exposure'=>[1,2,3]],
    'top_opportunities'=>[['opportunity'=>'SecretAlpha','approved_capital'=>'3000']],
    'venue_rows'=>[['venue'=>['id'=>'X'],'capital_locations'=>[['amount'=>'120000']],'exposure'=>['gross'=>'3000']]],
    'strategies'=>[['strategy'=>'Alpha','capital'=>'10000','net_pnl'=>'500','mode'=>'PAPER']],
    'decision_trace'=>[['type'=>'Execution','label'=>'secret-execution','value'=>'500']],
    'active_strategies'=>[['strategy'=>'SecretStrategy','allocated_capital'=>'10000']],
    'opportunities'=>[[
        'id'=>'opp-1','opportunity'=>'PublicSignal','expected_net'=>'50',
        'approved_capital'=>'3000','portfolio_impact'=>'APPROVED',
        'decision'=>'ACCEPT','priority'=>1,'reason'=>'Risk budget',
    ]],
    'recommended_actions'=>[
        ['href'=>'/capital-markets/risk','label'=>'Risk details'],
        ['href'=>'/capital-markets/opportunities/opp-1','label'=>'SecretAlpha'],
        ['href'=>'/capital-markets/performance','label'=>'Reconcile NAV'],
        ['href'=>'/capital-markets/data-quality','label'=>'Quality'],
        ['href'=>'/capital-markets/allocation','label'=>'Rebalance'],
    ],
    'partial_errors'=>[],
];

$none = DecisionWorkspaceVisibilityPolicy::redact($source, ['view'=>true]);
foreach (['portfolio_equity','available_capital','deployed_capital','reserved_capital','net_pnl','today_net_pnl','pnl_30d','portfolio_updated_at',
    'paper_equity','paper_nav_status','paper_today_net_pnl','paper_30d_net_pnl'] as $key) {
    $assert($none['global'][$key] === null, 'Generic View leaked financial field '.$key);
}
$assert($none['global']['portfolio_nav_windows'] === [], 'Generic View leaked NAV evidence.');
$assert($none['global']['risk_state'] === 'RESTRICTED', 'Generic View leaked risk state.');
$assert($none['global']['alerts'] === [] && $none['global']['critical_alerts'] === 0, 'Restricted alerts leaked.');
$assert($none['global']['last_updated'] === $source['global']['market_updated_at'], 'Hidden portfolio timestamp leaked via fallback.');
foreach (['capital','performance','exposure','portfolio','paper_nav','capital_map','risk','material_risks','top_opportunities','active_strategies'] as $key) {
    $assert(($none[$key] ?? null) === [], 'Generic View leaked payload '.$key);
}
$assert($none['recommended_actions'] === [], 'Generic View leaked restricted action descriptions.');
$assert($none['venue_rows'][0]['capital_locations']===[] && $none['venue_rows'][0]['exposure']===null,
    'Market venue rows leaked financial custody or exposure.');
$assert($none['strategies'][0]['capital']===null && $none['strategies'][0]['net_pnl']===null,
    'Research strategy list leaked real portfolio capital or profits.');
$assert($none['decision_trace']===[], 'Research hypothesis trace exposed execution or realized PnL.');

$assert($source['global']['portfolio_equity'] === '120000', 'The input projection must stay immutable.');

$signal = DecisionWorkspaceVisibilityPolicy::redact($source, ['opportunity_view'=>true]);
$assert($signal['opportunities'][0]['opportunity'] === 'PublicSignal', 'OpportunityView lost allowed signal.');
foreach (['approved_capital','priority'] as $key) {
    $assert($signal['opportunities'][0][$key] === null, 'OpportunityView leaked allocation '.$key);
}
$assert($signal['opportunities'][0]['portfolio_impact'] === 'RESTRICTED'
    && $signal['opportunities'][0]['decision'] === 'RESTRICTED'
    && $signal['opportunities'][0]['reason'] === '', 'OpportunityView leaked portfolio allocation verdict.');

$both = DecisionWorkspaceVisibilityPolicy::redact($source, [
    'view'=>true,'portfolio_view'=>true,'risk_view'=>true,
    'opportunity_view'=>true,'research_view'=>true,
    'allocation_view'=>true,'market_data_quality_view'=>true,
]);
$assert($both === $source, 'Full capability holder must receive the same canonical projection.');

$portfolioOnly = DecisionWorkspaceVisibilityPolicy::redact($source, ['portfolio_view'=>true]);
$assert($portfolioOnly['global']['portfolio_equity'] === '120000', 'PortfolioView must retain amounts.');
$assert($portfolioOnly['active_strategies'] === [], 'PortfolioView alone must not enumerate restricted strategy names.');

echo "CM-DECISION-WORKSPACE capability visibility regression passed.\n";
