<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2PublicationWatchdog.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2PublicationWatchdog;
$watchdog = new L2PublicationWatchdog(1000);
if (!$watchdog->isStale(0)) throw new RuntimeException('Uninitialized watchdog must be stale.');
$watchdog->published(1000);
if ($watchdog->isStale(2000) || !$watchdog->isStale(2001)) throw new RuntimeException('Publication freshness boundary failed.');
$watchdog->reset();
if (!$watchdog->isStale(2001)) throw new RuntimeException('Reset must revoke freshness.');
echo "L2 publication watchdog smoke passed\n";
