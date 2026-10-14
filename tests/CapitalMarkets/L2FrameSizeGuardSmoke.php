<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2FrameSizeGuard.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2FrameSizeGuard;
$g = new L2FrameSizeGuard(1024);
$g->assertAllowed(str_repeat('x',1024));
foreach (['',str_repeat('x',1025)] as $frame) {
    $rejected = false;
    try { $g->assertAllowed($frame); } catch (DomainException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Invalid frame size accepted.');
}
echo "L2 frame size guard smoke passed\n";
