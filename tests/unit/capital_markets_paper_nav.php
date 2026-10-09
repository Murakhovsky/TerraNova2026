<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\PaperNavValuation;
use Domains\CapitalMarkets\Application\Service\PaperNavWindowProjector;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $truth,string $why):void {if(!$truth)throw new RuntimeException($why);};
$at=new DateTimeImmutable('2026-10-09T12:00:00Z');
$capital=[
    'currency'=>'USD','initial_capital'=>'10000','available_capital'=>'8000',
    'reserved_capital'=>'2000','realized_pnl'=>'0','updated_at'=>'2026-10-09T11:59:59Z',
];
$noPositions=PaperNavValuation::calculate($capital,[],[],[],$at);
$assert($noPositions['status']==='SIMULATED'
    && $noPositions['equity']==='10000' && $noPositions['certified']===false,
    'Empty paper portfolio should use explicitly initialized capital without pretending to be certified.');
$assert($noPositions['ledger_reconciled']===false
    && $noPositions['external_flows_reconciled']===false,
    'Simulated evidence must never set financial certification gates.');

$position=[
    'portfolio_id'=>'paper:execution-1','position_id'=>'pos-1','instrument_id'=>'AAPLx',
    'venue_id'=>'venue-1','instrument_kind'=>'TOKENIZED_EQUITY',
    'status'=>'OPEN','side'=>'LONG','quantity'=>'2','average_entry_price'=>'100',
];
$balances=[
    ['venue_id'=>'venue-1','asset_key'=>'USD','available_amount'=>'200','reserved_amount'=>'50'],
    ['venue_id'=>'venue-1','asset_key'=>'AAPLx','available_amount'=>'1.5','reserved_amount'=>'0.5'],
];
$mark=[
    'status'=>'CANDIDATE','position_id'=>'pos-1','instrument_id'=>'AAPLx',
    'venue_id'=>'venue-1','quote_currency'=>'USD','quantity'=>'2',
    'mark_mid'=>'120','candidate_market_value'=>'240',
    'mark_source_fingerprint'=>str_repeat('a',64),'source_timestamp'=>'2026-10-09T11:59:55Z',
    'market_state_version'=>4,
];
$up=PaperNavValuation::calculate($capital,[$position],$balances,['pos-1'=>$mark],$at);
$assert($up['status']==='SIMULATED' && $up['equity']==='10040'
    && $up['unrealized_pnl']==='40' && $up['net_pnl']==='40',
    'Mark-to-entry contribution must add only unrealized value, not entire inventory market value.');
$assert($up['cumulative_external_net_flow']==='10000',
    'Initial funded paper capital must be recorded as the simulation external baseline.');

$off=$capital;$off['reserved_capital']='1000';
$blocked=PaperNavValuation::calculate($off,[],[],[],$at);
$assert($blocked['status']==='UNAVAILABLE'
    && in_array('PAPER_CAPITAL_BOOK_MISMATCH',$blocked['reasons'],true),
    'Broken reserved + available = initial + realized invariant must block NAV.');

$notInventoried=PaperNavValuation::calculate($capital,[$position],[],['pos-1'=>$mark],$at);
$assert($notInventoried['status']==='UNAVAILABLE'
    && in_array('PAPER_POSITION_INVENTORY_MISSING',$notInventoried['reasons'],true),
    'No open position may be valued without matching paper inventory.');
$mismatch=$balances;$mismatch[1]['available_amount']='3';
$badInventory=PaperNavValuation::calculate($capital,[$position],$mismatch,['pos-1'=>$mark],$at);
$assert(in_array('PAPER_INVENTORY_RECONCILIATION_REQUIRED',$badInventory['reasons'],true),
    'Quantity discrepancies must never inflate paper assets.');
$perp=$position;$perp['instrument_kind']='PERPETUAL';
$derivative=PaperNavValuation::calculate($capital,[$perp],$balances,['pos-1'=>$mark],$at);
$assert($derivative['status']==='UNAVAILABLE'
    && in_array('PAPER_DERIVATIVE_SHORT_OR_UNCLASSIFIED_POSITION_UNSUPPORTED',$derivative['reasons'],true),
    'Perpetual must not inherit spot-only paper valuation.');
$stale=$mark;$stale['source_timestamp']='2026-10-09T11:59:00Z';
$old=PaperNavValuation::calculate($capital,[$position],$balances,['pos-1'=>$stale],$at);
$assert($old['status']==='UNAVAILABLE'
    && in_array('PAPER_MARK_QUANTITY_AGE_OR_ENTRY_INVALID',$old['reasons'],true),
    'Stale market mark cannot enter simulated PnL.');
$future=$mark;$future['source_timestamp']='2026-10-09T12:00:30Z';
$clock=PaperNavValuation::calculate($capital,[$position],$balances,['pos-1'=>$future],$at);
$assert($clock['status']==='UNAVAILABLE','Future market timestamp must be blocked.');
$cashOnly=$balances;
$cashOnly[]=['venue_id'=>'venue-1','asset_key'=>'BTC','available_amount'=>'0.1','reserved_amount'=>'0'];
$unknown=PaperNavValuation::calculate($capital,[$position],$cashOnly,['pos-1'=>$mark],$at);
$assert($unknown['status']==='UNAVAILABLE','Unknown unvalued noncash asset must block NAV.');

$snapshot=static fn(string $timestamp,string $equity,string $initial='10000',string $flow='10000',string $epoch='epoch-a'):array=>[
    'mode'=>'PAPER','status'=>'SIMULATED','valuation_status'=>'SIMULATED',
    'valued_at'=>$timestamp,'equity'=>$equity,'initial_capital'=>$initial,
    'cumulative_external_net_flow'=>$flow,'currency'=>'USD','portfolio_epoch'=>$epoch,
    'certified'=>false,'ledger_reconciled'=>false,'external_flows_reconciled'=>false,
    'source_fingerprint'=>str_repeat('b',64),
];
$window=PaperNavWindowProjector::project([
    $snapshot('2026-09-09T12:00:00Z','10000'),
    $snapshot('2026-10-09T00:00:00Z','10200'),
    $snapshot('2026-10-09T12:00:00Z','10300'),
],$at);
$assert($window['latest']['status']==='SIMULATED' && $window['latest']['equity']==='10300',
    'Current marked paper equity should be available from a fresh simulated snapshot.');
$assert($window['windows']['today']['status']==='SIMULATED'
    && $window['windows']['today']['net_pnl']==='100',
    'Today paper net PnL requires both UTC opening and closing snapshots.');
$assert($window['windows']['30d']['status']==='SIMULATED'
    && $window['windows']['30d']['net_pnl']==='300',
    '30D rolling paper net PnL requires a real saved 30-day opening boundary.');
$short=PaperNavWindowProjector::project([$snapshot('2026-10-09T12:00:00Z','10300')],$at);
$assert($short['windows']['today']['net_pnl']===null
    && $short['windows']['30d']['net_pnl']===null,
    'One snapshot cannot fabricate 0 or nonzero historical paper profit.');
$unverified=$snapshot('2026-10-09T12:00:00Z','999999');
$unverified['certified']=true;
$blockedWindows=PaperNavWindowProjector::project([
    $snapshot('2026-10-09T00:00:00Z','10000'),$unverified,
],$at);
$assert($blockedWindows['latest']['equity']===null
    && $blockedWindows['windows']['today']['net_pnl']===null,
    'Forged authority flags cannot pass even a simulation-only read model.');
$reset=PaperNavWindowProjector::project([
    $snapshot('2026-10-09T00:00:00Z','10000'),
    $snapshot('2026-10-09T12:00:00Z','20000','20000','20000'),
],$at);
$assert($reset['windows']['today']['net_pnl']===null,
    'Paper account reinitialization cannot masquerade as 10000 profit.');
$sameCapitalReset=PaperNavWindowProjector::project([
    $snapshot('2026-10-09T00:00:00Z','10020'),
    $snapshot('2026-10-09T12:00:00Z','10000','10000','10000','epoch-b'),
],$at,900,'epoch-b');
$assert($sameCapitalReset['windows']['today']['net_pnl']===null,
    'Same-capital reset must not inherit profit or loss from previous paper epoch.');
$oldEpoch=PaperNavWindowProjector::project([
    $snapshot('2026-10-09T11:59:00Z','10020'),
],$at,900,'epoch-b');
$assert($oldEpoch['latest']['equity']===null,
    'After reset, latest old-epoch equity is not current paper NAV.');
$duplicate=PaperNavWindowProjector::project([
    $snapshot('2026-10-09T00:00:00Z','10000'),
    $snapshot('2026-10-09T12:00:00Z','10300'),
    $snapshot('2026-10-09T12:00:00Z','10400'),
],$at);
$assert($duplicate['windows']['today']['net_pnl']===null
    && $duplicate['latest']['equity']===null,
    'Ambiguous paper snapshot time must not arbitrarily choose equity or PnL.');
echo "Capital Markets paper simulated NAV and 30D window invariants passed.\n";
