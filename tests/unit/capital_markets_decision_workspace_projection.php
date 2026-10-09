<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\DecisionWorkspaceReadService;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

// Read-side mapping is deliberately independent from backend services so that
// real producer payload shapes can be tested without fake financial engines.
$ref = new ReflectionClass(DecisionWorkspaceReadService::class);
$view = $ref->newInstanceWithoutConstructor();
$rowsMethod = $ref->getMethod('opportunityRows');
$filterMethod = $ref->getMethod('filterOpportunityRows');
$economicsMethod = $ref->getMethod('economics');
$risksMethod = $ref->getMethod('materialRisks');
$evidenceMethod = $ref->getMethod('opportunityEvidence');

$token = [
    'id' => 'token-1',
    'type' => 'TOKENIZED_SPREAD',
    'expected_pnl' => '42.50',
    'expected_net_edge_bps' => '28.5',
    'required_capital' => '10000',
    'capital_capacity' => '15000',
    'risk_score' => 15,
    'expires_at' => (new DateTimeImmutable('+20 minutes'))->format(DATE_ATOM),
    'risk' => ['decision' => 'APPROVE', 'risk_score' => 15],
    'candidate' => [
        'buy_instrument_id' => 'AAPLx',
        'sell_instrument_id' => 'AAPL',
        'buy_venue_id' => 'KRAKEN',
        'sell_venue_id' => 'NASDAQ',
    ],
];
$crypto = [
    'id' => 'crypto-1',
    'type' => 'SPOT_PERP_BASIS',
    'expected_pnl' => '21',
    'expected_net_edge_bps' => '13',
    'capital_capacity' => '24000',
    'required_capital' => '12000',
    'expires_at' => (new DateTimeImmutable('+2 hours'))->format(DATE_ATOM),
    'legs' => [
        ['instrument_id' => 'BTC/USDT', 'venue_id' => 'BINANCE'],
        ['instrument_id' => 'BTCUSDT-PERP', 'venue_id' => 'BYBIT'],
    ],
    'risk' => ['risk_score' => 22],
];
$rows = $rowsMethod->invoke($view, ['opportunities' => [$token, $crypto]]);
$assert(count($rows) === 2, 'Both canonical opportunities must be shown.');
$byId = array_column($rows, null, 'id');
$assert($byId['token-1']['expected_net'] === '42.50', 'Expected Net must read expected_pnl.');
$assert($byId['token-1']['expected_return'] === '28.5' && $byId['token-1']['expected_return_unit'] === 'bps', 'Canonical return must retain bps units.');
$assert($byId['token-1']['capacity'] === '15000', 'Capacity must read capital_capacity.');
$assert($byId['token-1']['risk'] === 'SCORE 15', 'Nested risk cannot be cast to string.');
$assert($byId['token-1']['instruments'] === ['AAPLx','AAPL'], 'Tokenized candidate instruments must be projected.');
$assert($byId['token-1']['venues'] === ['KRAKEN','NASDAQ'], 'Tokenized candidate venues must be projected.');
$assert($byId['crypto-1']['instruments'] === ['BTC/USDT','BTCUSDT-PERP'], 'Crypto legs must be projected.');
$assert($byId['crypto-1']['venues'] === ['BINANCE','BYBIT'], 'Crypto venues must be projected.');
$assert(count($filterMethod->invoke($view, $rows, ['view' => 'short-ttl'])) === 1, 'Short TTL must understand ISO expiration.');
$assert(count($filterMethod->invoke($view, $rows, ['view' => 'high-capacity'])) === 2, 'High Capacity must use canonical capacity.');
$assert(count($filterMethod->invoke($view, $rows, ['instrument' => 'AAPLx'])) === 1, 'Nested instrument filter must work.');
$assert(count($filterMethod->invoke($view, $rows, ['venue' => 'BYBIT'])) === 1, 'Nested venue filter must work.');
$assert(count($filterMethod->invoke($view, $rows, ['min_net' => '30'])) === 1, 'Min expected net must read canonical value.');
$assert($economicsMethod->invoke($view, $token)['expected_net'] === '42.50', 'Detail expected net must share canonical value.');
$evidence = $evidenceMethod->invoke($view, $token, [
    'states' => [['instrument_id' => 'AAPLx', 'source_id' => 'feed']],
    'reference_states' => [['instrument_id' => 'AAPL', 'source_id' => 'reference']],
]);
$assert(count($evidence['market_states']) === 1, 'Nested instruments must resolve current market evidence.');
$assert(count($evidence['reference_states']) === 1, 'Nested instruments must resolve reference evidence.');

$risks = $risksMethod->invoke($view, [
    'risk' => ['risk_limit_utilization' => [
        'venue_critical' => ['utilization' => '0.88', 'current' => '880', 'limit' => '1000', 'breached' => false],
        'asset_ok' => ['utilization' => '0.35', 'current' => '350', 'limit' => '1000', 'breached' => false],
    ]],
]);
$assert(count($risks) === 1 && $risks[0]['state'] === 'CAUTION', '80% utilization must be read as decimal ratio.');

// A combined "last updated" field must not mislabel portfolio time as market time.
$globalState = $ref->getMethod('globalState');
$global = $globalState->invoke($view, [
    'capital_state' => ['timestamp' => '2026-10-08T08:00:00+00:00'],
], [
    'sources' => [],
    'states' => [['updated_at' => '2026-10-08T08:03:00+00:00', 'trust_status' => 'TRUSTED']],
    'reference_states' => [],
], 'PAPER');
$assert($global['portfolio_updated_at'] === '2026-10-08T08:00:00+00:00', 'Portfolio update provenance must stay separate.');
$assert($global['market_updated_at'] === '2026-10-08T08:03:00+00:00', 'Market update provenance must stay separate.');

$recommend = $ref->getMethod('recommendedActions');
$missingNav = $recommend->invoke($view, [
    'alerts' => [],
    'portfolio_nav_windows' => ['today' => ['status' => 'UNAVAILABLE', 'net_pnl' => null]],
], [], []);
$assert(count($missingNav) === 1
    && $missingNav[0]['reason'] === 'PORTFOLIO_NAV_UNAVAILABLE',
    'An incomplete NAV window must appear as an operator reconciliation task.');
$verifiedZero = $recommend->invoke($view, [
    'alerts' => [],
    'portfolio_nav_windows' => ['today' => ['status' => 'COMPLETE', 'net_pnl' => '0']],
], [], []);
$assert($verifiedZero === [], 'A verified zero must not trigger a missing NAV action.');

echo "CM-DECISION-WORKSPACE canonical projection regression passed.\n";
