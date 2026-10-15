<?php
declare(strict_types=1);

$root = dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
require_once $root.'L2ShutdownSignal.php';
require_once $root.'L2OperationalMetrics.php';

use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ShutdownSignal;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2OperationalMetrics;

$signal = new L2ShutdownSignal();
if ($signal->shouldStop()) throw new RuntimeException('Premature stop request.');
$signal->requestStop();
if (!$signal->shouldStop()) throw new RuntimeException('Stop request ignored.');

$metrics = new L2OperationalMetrics();
if (!$metrics->snapshot(100)['stale']) throw new RuntimeException('Unknown stream should be stale.');
$metrics->connection(1);
$metrics->connection(2);
$metrics->publication(100);
$metrics->failure('socket reset');
$snapshot = $metrics->snapshot(120,30);
if ($snapshot['connections'] !== 2 || $snapshot['reconnects'] !== 1
    || $snapshot['publications'] !== 1 || $snapshot['failures'] !== 1
    || $snapshot['stale'] || $snapshot['last_failure'] !== 'socket reset') {
    throw new RuntimeException('L2 operational metrics regression.');
}
if (!$metrics->snapshot(131,30)['stale']) throw new RuntimeException('Stale stream not detected.');
echo "L2 operations smoke passed\n";
