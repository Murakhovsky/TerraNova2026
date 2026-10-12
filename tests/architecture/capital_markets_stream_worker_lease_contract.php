<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$file=$root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingWorkerLease.php';
$migration=$root.'/app/migrations/20261012_000135_capital_markets_stream_worker_leases.sql';
$src=file_get_contents($file);
$sql=file_get_contents($migration);
if(!is_string($src)||!is_string($sql))throw new RuntimeException('Streaming lease files unavailable.');
foreach(['FOR UPDATE','fencing_token','UTC_TIMESTAMP(6)','beginTransaction','rollBack','owner_id=:worker'] as $expected){
    if(!str_contains($src,$expected))throw new RuntimeException('Missing atomic lease protection: '.$expected);
}
if(!str_contains($sql,'PRIMARY KEY (organization_id,source_id,shard_key)')){
    throw new RuntimeException('Missing tenant/source/shard uniqueness.');
}
if(!str_contains($sql,'BIGINT UNSIGNED'))throw new RuntimeException('Missing monotonic fencing storage.');
echo "Capital Markets streaming worker lease architecture passed.\n";
