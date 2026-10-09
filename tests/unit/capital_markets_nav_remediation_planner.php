<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\PortfolioNavRemediationPlanner;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$assert=static function (bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$preflight=[
    'status'=>'BLOCKED',
    'issues'=>[
        'EXTERNAL_FLOW_LEDGER_UNAVAILABLE',
        'LEDGER_ASSET_IMBALANCE',
        'POSITION_MARK_UNTRUSTED',
        'VENUE_CASH_MISMATCH',
        'EXTERNAL_FLOW_LEDGER_UNAVAILABLE',
        'POSITION_FX_CONVERSION_REQUIRED',
        'NAV_SOURCE_EVIDENCE_INVALID',
    ],
    'statement_reconciliation_preview'=>[
        'venue_comparison'=>[
            ['venue_id'=>'kraken','same_amount'=>false],
            ['venue_id'=>'binance','same_amount'=>true],
            ['venue_id'=>'kraken','same_amount'=>false],
        ],
    ],
];
$plan=PortfolioNavRemediationPlanner::plan($preflight);
$assert($plan['status']==='BLOCKED','Unreconciled NAV cannot become ready.');
$assert($plan['snapshot_write_allowed']===false && $plan['financial_authority']==='DIAGNOSTIC_ONLY',
    'Remediation plans cannot approve NAV snapshots.');
$assert($plan['open_issue_codes']===6,'Issue code repeats must be deduplicated.');
$assert($plan['unmatched_venues']===['kraken'],'Only unmatched distinct venue IDs should appear.');
$assert(count($plan['tasks'])>=4,'Source, trading ledger, venue cash and market mark actions are expected.');
$assert($plan['tasks'][0]['id']==='SOURCE_DOCUMENTS','Source documents should be the first remediation step.');
$assert($plan['tasks'][0]['status']==='OPEN' && $plan['tasks'][0]['issue_codes']!==[],
    'Every surfaced task must retain source blockers and remain open.');
$assert(PortfolioNavRemediationPlanner::plan($preflight)===$plan,
    'Remediation ordering and grouping must be deterministic.');

$unknown=PortfolioNavRemediationPlanner::plan([
    'status'=>'BLOCKED',
    'issues'=>['NEW_RISK_FLAG','bad-issue','NEW_RISK_FLAG'],
]);
$assert($unknown['open_issue_codes']===2
    && $unknown['tasks'][0]['id']==='CERTIFICATION'
    && in_array('UNRECOGNIZED_PREFLIGHT_ISSUE',$unknown['tasks'][0]['issue_codes'],true),
    'Unrecognized blocker must remain visible and require independent review.');

$missing=PortfolioNavRemediationPlanner::plan(['status'=>'UNAVAILABLE','issues'=>[]]);
$assert($missing['status']==='BLOCKED'
    && $missing['open_issue_codes']===1
    && $missing['snapshot_write_allowed']===false,
    'An empty or broken preflight report cannot authorize NAV.');

$apparentlyReady=PortfolioNavRemediationPlanner::plan(['status'=>'READY','issues'=>[]]);
$assert($apparentlyReady['status']==='AWAITING_INDEPENDENT_ACCEPTANCE'
    && $apparentlyReady['snapshot_write_allowed']===false,
    'Preflight readiness does not substitute independent financial approval.');
$assert($apparentlyReady['open_workstreams']===1
    && $apparentlyReady['tasks'][0]['id']==='CERTIFICATION',
    'A clean NAV preflight still requires visible independent financial certification.');

echo "Capital Markets NAV remediation planning passed.\n";
