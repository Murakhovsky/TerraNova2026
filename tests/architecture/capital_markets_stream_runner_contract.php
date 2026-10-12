<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$code=file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/StreamingWorkerRunner.php');
if(!is_string($code))throw new RuntimeException('Worker runner missing.');
foreach(['->acquire(','->renew(','->owns(','->invalidate(','->release(','->close(','->pump('] as $s){
    if(!str_contains($code,$s))throw new RuntimeException('Missing worker safety behavior: '.$s);
}
echo "Streaming worker runtime contract passed.\n";
