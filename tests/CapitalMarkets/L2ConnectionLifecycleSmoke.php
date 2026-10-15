<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
require_once $root.'L2ReconnectPolicy.php';
require_once $root.'L2ConnectionLifecycle.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ReconnectPolicy;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ConnectionLifecycle;
$life = new L2ConnectionLifecycle(new L2ReconnectPolicy(100, 400, 3));
$epoch = $life->start();
if ($life->isTrusted($epoch)) throw new RuntimeException('Premature trust.');
$life->subscribed($epoch);
$life->trusted($epoch);
if (!$life->isTrusted($epoch)) throw new RuntimeException('Trust missing.');
if ($life->failed($epoch, 'disconnect') !== 100 || $life->isTrusted($epoch)) throw new RuntimeException('Disconnect safety.');
$new = $life->start();
if ($life->isTrusted($epoch) || $new === $epoch) throw new RuntimeException('Stale epoch trusted.');
$rejected = false;
try { $life->subscribed($epoch); } catch (DomainException $e) { $rejected = true; }
if (!$rejected) throw new RuntimeException('Stale acknowledgement accepted.');
$life->stop();
if ($life->isTrusted($new)) throw new RuntimeException('Stopped stream trusted.');
echo "L2 connection lifecycle smoke passed\n";
