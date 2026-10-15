<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2StreamHealth.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2StreamHealth;
$h = new L2StreamHealth();
if (!$h->isStale(1000,500)) throw new RuntimeException('Uninitialized stream considered fresh.');
$h->record(1000,'DATA');
$h->record(1200,'CONTROL');
if ($h->isStale(1500,500)) throw new RuntimeException('Valid data considered stale.');
if (!$h->isStale(1501,500)) throw new RuntimeException('Heartbeat masked stale data.');
$d = $h->diagnostics();
if ($d['data_frames'] !== 1 || $d['control_frames'] !== 1) throw new RuntimeException('Counters incorrect.');
echo "L2 stream health smoke passed\n";
