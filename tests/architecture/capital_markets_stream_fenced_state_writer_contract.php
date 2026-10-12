<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$writer=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingFencedStateWriter.php');
$module=file_get_contents($root.'/app/Domains/CapitalMarkets/module.php');
if(!is_string($writer)||!is_string($module))throw new RuntimeException('Streaming writer source missing.');
foreach(['FOR UPDATE','expires_at > UTC_TIMESTAMP(6)','fencing_token=:token','beginTransaction()','commit()','rollBack()','saveReference(','save('] as $required){
    if(!str_contains($writer,$required))throw new RuntimeException('Missing streaming fenced writer invariant: '.$required);
}
if(!str_contains($module,'20261012_000135_capital_markets_stream_worker_leases.sql')){
    throw new RuntimeException('Streaming lease migration not registered.');
}
echo "Capital Markets fenced streaming writer contract passed.\n";
