<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$src=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlBinanceStreamingExpiredLeaseSweeper.php');
$invalidator=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingTrustInvalidator.php');
$command=file_get_contents($root.'/symfony/src/Command/CapitalMarketsBinanceStreamingSweepCommand.php');
foreach([$src,$invalidator,$command] as $file){
    if(!is_string($file)||$file==='')throw new RuntimeException('Expired streaming sweep files missing.');
}
foreach(['FOR UPDATE','fencing_token=:observed_token','expires_at<=UTC_TIMESTAMP(6)',
'OWNER_CHANGED','LEASE_EXPIRED','fencing_token=:token'] as $needle){
    if(!str_contains($src,$needle))throw new RuntimeException('Missing independent sweep invariant '.$needle);
}
if(!str_contains($invalidator,"trust_status IN (\\'TRUSTED\\',\\'DEGRADED\\')")){
    throw new RuntimeException('Degraded state still allowed after lease loss.');
}
if(!str_contains($invalidator,"connection_state=\\'DEGRADED\\'")){
    throw new RuntimeException('Source health not downgraded on trust invalidation.');
}
if(!str_contains($command,'COS_CM_STREAMING_BINANCE_SPOT_PILOT')){
    throw new RuntimeException('Sweeper pilot flag absent.');
}
echo "Independent streaming lease expiry sweep contract passed.\n";
