<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\RealizedPnlWindowProjector;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$at = new DateTimeImmutable('2026-10-08T12:00:00+00:00');
$token = static fn(string $at, string $amount, string $currency = 'USD'): array => [
    'status' => 'COMPLETED',
    'executed_at' => $at,
    'quote_asset' => $currency,
    'realized_pnl' => $amount,
    'fees' => ['buy' => '0.25', 'sell' => '0.25'],
];
$relative = static fn(string $at, string $net, string $currency = 'USD'): array => [
    'status' => 'CLOSED',
    'closed_at' => $at,
    'quote_asset' => $currency,
    'performance' => [
        'spot_price_pnl' => '10', 'derivative_price_pnl' => '-8',
        'funding_pnl' => '0', 'trading_fees' => '1',
        'borrow_cost' => '0', 'network_costs' => '0',
        'net_pnl' => $net,
    ],
];
$rows = [
    $token('2026-10-08T09:00:00+00:00', '9.25'),
    $relative('2026-10-08T10:00:00+00:00', '-2.00'),
    $token('2026-09-20T10:00:00+00:00', '3.00'),
    $token('2026-08-01T10:00:00+00:00', '100.00'),
    ['status' => 'OPEN', 'opened_at' => '2026-10-08T10:00:00+00:00', 'quote_asset' => 'USD', 'realized_pnl' => '9999'],
];
$w = RealizedPnlWindowProjector::project($rows, $at);
$assert($w['today']['status'] === 'COMPLETE', 'Today fully evidenced realized window must be COMPLETE.');
$assert($w['today']['net_pnl'] === '7.25', 'Today realized net must include completed rows only, without float arithmetic.');
$assert($w['today']['coverage'] === '2/2', 'Today coverage is incorrect.');
$assert($w['today']['currency'] === 'USD', 'Today must retain one verified settlement currency.');
$assert($w['30d']['status'] === 'COMPLETE' && $w['30d']['net_pnl'] === '10.25', '30D must exclude events older than 30 days.');
$assert($w['30d']['scope'] === 'REALIZED_EXECUTIONS_ONLY', 'Realized scope must never masquerade as total Portfolio P&L.');

$zero = RealizedPnlWindowProjector::project([$token('2026-10-08T07:00:00+00:00', '0')], $at);
$assert($zero['today']['status'] === 'COMPLETE' && $zero['today']['net_pnl'] === '0', 'Canonical zero must not be displayed as unavailable.');

$mixed = RealizedPnlWindowProjector::project([
    $token('2026-10-08T07:00:00+00:00', '5', 'USD'),
    $token('2026-10-08T08:00:00+00:00', '5', 'EUR'),
], $at);
$assert($mixed['today']['net_pnl'] === null && in_array('MIXED_CURRENCIES', $mixed['today']['issues'], true), 'Different settlement currencies must never be summed.');

$unknownCurrency = RealizedPnlWindowProjector::project([
    ['status' => 'COMPLETED', 'executed_at' => '2026-10-08T07:00:00+00:00', 'realized_pnl' => '5', 'fees' => []],
], $at);
$assert($unknownCurrency['today']['net_pnl'] === null && $unknownCurrency['today']['status'] === 'PARTIAL', 'Currency uncertainty must block time-window money.');

$undated = RealizedPnlWindowProjector::project([
    $token('2026-10-08T08:00:00+00:00', '5'),
    ['status' => 'COMPLETED', 'quote_asset' => 'USD', 'realized_pnl' => '4', 'fees' => []],
], $at);
$assert($undated['today']['net_pnl'] === null && in_array('UNDATED_EXECUTIONS', $undated['today']['issues'], true), 'Undated eligible execution must block false completeness.');

$costGap = RealizedPnlWindowProjector::project([
    ['status' => 'CLOSED', 'closed_at' => '2026-10-08T07:00:00+00:00', 'quote_asset' => 'USD', 'performance' => ['net_pnl' => '50']],
], $at);
$assert($costGap['today']['net_pnl'] === null && in_array('ECONOMICS_INCOMPLETE', $costGap['today']['issues'], true), 'Missing cost decomposition must not become realized P&L.');

$empty = RealizedPnlWindowProjector::project([], $at);
$assert($empty['today']['status'] === 'UNAVAILABLE' && $empty['today']['net_pnl'] === null, 'No evidence must not become zero P&L.');

$offset = RealizedPnlWindowProjector::project([$token('2026-10-07T22:30:00-02:00', '2.5')], $at);
$assert($offset['today']['net_pnl'] === '2.5', 'Today boundary must use normalized UTC time.');

echo "Capital Markets canonical realized-window projection passed.\n";
