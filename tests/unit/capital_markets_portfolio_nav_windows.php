<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\PortfolioNavWindowProjector;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert = static function(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException($message);
};
$at = new DateTimeImmutable('2026-10-08T12:00:00Z');
$snapshot = static fn(string $stamp,string $equity,string $flows,string $currency='USD'):array => [
    'valued_at'=>$stamp, 'equity'=>$equity, 'cumulative_external_net_flow'=>$flows,
    'currency'=>$currency, 'valuation_status'=>'COMPLETE',
    'ledger_reconciled'=>true,'marks_reconciled'=>true,'external_flows_reconciled'=>true,
    'provenance_id'=>'verified-accounting-run','ledger_fingerprint'=>str_repeat('a',64),
    'marks_fingerprint'=>str_repeat('b',64),'external_flows_fingerprint'=>str_repeat('c',64),
];
$rows = [
    $snapshot('2026-09-08T12:00:00Z','1000','0'),
    $snapshot('2026-10-08T00:00:00Z','1200','0'),
    $snapshot('2026-10-08T12:00:00Z','1250','100'),
];
$window = PortfolioNavWindowProjector::project($rows,$at);
$assert($window['today']['status']==='COMPLETE' && $window['today']['net_pnl']==='-50','Today NAV performance must deduct external deposits.');
$assert($window['30d']['status']==='COMPLETE' && $window['30d']['net_pnl']==='150','30D NAV performance must use the verified 30-day opening balance.');
$assert($window['today']['currency']==='USD','Valuation currency must come from reconciled snapshots.');
$assert($window['today']['scope']==='PORTFOLIO_NAV_REALIZED_AND_UNREALIZED','Portfolio NAV must not be relabeled realized execution PnL.');
$zero=PortfolioNavWindowProjector::project([
    $snapshot('2026-10-08T00:00:00Z','1000','0'),
    $snapshot('2026-10-08T12:00:00Z','1000','0'),
],$at);
$assert($zero['today']['net_pnl']==='0','True zero NAV performance is a valid result.');
$missing=PortfolioNavWindowProjector::project([
    $snapshot('2026-10-08T12:00:00Z','1250','100'),
],$at);
$assert($missing['today']['status']==='UNAVAILABLE' && $missing['today']['net_pnl']===null,'Missing opening NAV must not be backfilled.');
$untrusted=$snapshot('2026-10-08T12:00:00Z','1250','100');
$untrusted['marks_reconciled']=false;
$guard=PortfolioNavWindowProjector::project([$rows[1],$untrusted],$at);
$assert($guard['today']['net_pnl']===null,'Unreconciled mark values cannot support a portfolio PnL total.');
$eur=PortfolioNavWindowProjector::project([
    $rows[1],$snapshot('2026-10-08T12:00:00Z','1250','100','EUR'),
],$at);
$assert($eur['today']['reason']==='NAV_CURRENCY_MISMATCH','Currency changes require verified conversion accounting.');
$late=PortfolioNavWindowProjector::project([
    $rows[1],$snapshot('2026-10-08T11:20:00Z','1250','100'),
],$at);
$assert($late['today']['reason']==='VALUATION_BOUNDARY_STALE','Stale terminal NAV cannot be labeled current.');
$malformed=PortfolioNavWindowProjector::project([
    $rows[1],['valued_at'=>'2026-10-08T12:00:00Z','equity'=>'unknown','currency'=>'USD',
       'cumulative_external_net_flow'=>'0','valuation_status'=>'COMPLETE',
       'ledger_reconciled'=>true,'marks_reconciled'=>true,'external_flows_reconciled'=>true],
],$at);
$assert($malformed['today']['net_pnl']===null,'Invalid decimal money evidence must be unavailable.');
$unproven=$snapshot('2026-10-08T12:00:00Z','1250','100');
unset($unproven['marks_fingerprint']);
$missingProvenance=PortfolioNavWindowProjector::project([$rows[1],$unproven],$at);
$assert($missingProvenance['today']['net_pnl']===null,'NAV records without source fingerprints must never contribute to financial totals.');

$duplicate=PortfolioNavWindowProjector::project([
    $rows[1],
    $snapshot('2026-10-08T12:00:00Z','1250','100'),
    $snapshot('2026-10-08T12:00:00Z','1300','100'),
],$at);
$assert($duplicate['today']['status']==='UNAVAILABLE'
    && $duplicate['today']['net_pnl']===null
    && $duplicate['today']['reason']==='CONFLICTING_VALUATION_TIMESTAMPS',
    'Conflicting same-timestamp NAV evidence must never produce an arbitrary profit.');

$intermediateCurrency=PortfolioNavWindowProjector::project([
    $rows[1],
    $snapshot('2026-10-08T06:00:00Z','1200','0','EUR'),
    $snapshot('2026-10-08T12:00:00Z','1250','100'),
],$at);
$assert($intermediateCurrency['today']['status']==='UNAVAILABLE'
    && $intermediateCurrency['today']['reason']==='INTERMEDIATE_NAV_CURRENCY_MISMATCH',
    'A currency change hidden between boundary NAV valuations must block performance.');

$corruptIntermediate=$snapshot('2026-10-08T06:00:00Z','1210','0');
$corruptIntermediate['marks_reconciled']=false;
$intermediateGap=PortfolioNavWindowProjector::project([
    $rows[1], $corruptIntermediate, $rows[2],
],$at);
$assert($intermediateGap['today']['status']==='UNAVAILABLE'
    && $intermediateGap['today']['reason']==='UNVERIFIED_VALUATION_IN_WINDOW'
    && $intermediateGap['today']['net_pnl']===null,
    'A rejected intermediate valuation must not disappear from an otherwise complete Today window.');

$badTimestamp=$snapshot('2026-10-08T06:00:00Z','1210','0');
$badTimestamp['valued_at']='unknown';
$undatedGap=PortfolioNavWindowProjector::project([
    $rows[1],$badTimestamp,$rows[2],
],$at);
$assert($undatedGap['today']['reason']==='UNVERIFIED_VALUATION_IN_WINDOW',
    'A snapshot without a verifiable event time must never be ignored.');

$outsideWindow=$snapshot('2026-09-01T12:00:00Z','900','0');
$outsideWindow['ledger_reconciled']=false;
$independentPeriod=PortfolioNavWindowProjector::project([
    $outsideWindow,...$rows,
],$at);
$assert($independentPeriod['today']['status']==='COMPLETE'
    && $independentPeriod['30d']['status']==='COMPLETE',
    'Invalid NAV evidence outside the evaluated window must not poison unrelated periods.');

echo "Capital Markets portfolio NAV window projection passed.\n";
