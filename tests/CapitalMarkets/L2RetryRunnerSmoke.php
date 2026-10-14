<?php
declare(strict_types=1);
$root = dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
require_once $root.'L2ReconnectPolicy.php';
require_once $root.'L2RetryRunner.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2ReconnectPolicy;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2RetryRunner;
$runner = new L2RetryRunner(new L2ReconnectPolicy(100,400,3));
$delays = [];
$result = $runner->run(static function(int $n):int {
    if ($n < 3) throw new RuntimeException('Transient disconnect.');
    return 42;
}, static function(int $delay) use (&$delays):void {$delays[] = $delay;});
if ($result !== 42 || $delays !== [100,200]) throw new RuntimeException('Reconnect policy regression.');
$failed = false;
try { $runner->run(static function():void {throw new RuntimeException('Permanent disconnect.');},static function():void {}); }
catch (DomainException $e) { $failed = $e->getPrevious() instanceof RuntimeException; }
if (!$failed) throw new RuntimeException('Retry budget did not fail closed.');
echo "L2 retry runner smoke passed\n";
