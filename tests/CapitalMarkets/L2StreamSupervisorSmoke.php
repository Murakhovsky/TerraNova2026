<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2ReconnectPolicy.php';
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2StreamSupervisor.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ReconnectPolicy;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2StreamSupervisor;
$s = new L2StreamSupervisor(new L2ReconnectPolicy(100,400,3));
$epoch = $s->start();
if ($s->mayPublish($epoch)) throw new RuntimeException('Unverified stream published.');
$s->connected($epoch);
$s->verified($epoch);
if (!$s->mayPublish($epoch)) throw new RuntimeException('Verified stream blocked.');
if ($s->failure($epoch,'disconnect') !== 100 || $s->mayPublish($epoch)) throw new RuntimeException('Failure did not revoke trust.');
$next = $s->start();
if ($s->mayPublish($epoch) || $next === $epoch) throw new RuntimeException('Stale epoch accepted.');
$s->stop();
if ($s->mayPublish($next)) throw new RuntimeException('Stopped stream published.');
echo "L2 stream supervisor smoke passed\n";
