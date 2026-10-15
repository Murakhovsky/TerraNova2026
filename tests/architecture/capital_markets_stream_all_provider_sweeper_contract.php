<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $value=file_get_contents($root.'/'.$path);
    if(!is_string($value)||$value==='')throw new RuntimeException('Missing sweep file: '.$path);
    return $value;
};
$service=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingExpiredLeaseSweeper.php');
$command=$read('symfony/src/Command/CapitalMarketsStreamingExpiredLeaseSweepCommand.php');
$runner=$read('app/Domains/CapitalMarkets/Application/Service/StreamingWorkerRunner.php');
$config=$read('symfony/config/services.yaml');
$ci=$read('.github/workflows/ci.yml');
$runtime=$read('.github/workflows/runtime.yml');

foreach(['binance-spot-single-bbo','bybit-spot-single-bbo','kraken-spot-single-bbo',
    'binance-usdm-single-bbo','bybit-linear-single-bbo',
    'MysqlMarketSourceWriterGate::lockSource(','FOR UPDATE','LEASE_EXPIRED',
    'fencing_token=:old_token','expires_at<=UTC_TIMESTAMP(6)',
    'owner_id=:owner AND fencing_token=:token',
] as $required){
    if(!str_contains($service,$required)){
        throw new RuntimeException('Multi-venue dead lease sweep missing '.$required);
    }
}
foreach(['COS_CM_STREAM_SWEEPER_ENABLED','sweepBatch(','InputOption::VALUE_REQUIRED'] as $required){
    if(!str_contains($command,$required))throw new RuntimeException('Unsafe sweeper operator CLI: '.$required);
}
if(!str_contains($runner,'if(!$invalidationFailed)try{$this->leases->release')){
    throw new RuntimeException('Failed invalidation incorrectly releases recoverable lease.');
}
if(!str_contains($config,'MysqlStreamingExpiredLeaseSweeper:')){
    throw new RuntimeException('Missing source sweeper MySQL service wiring.');
}
if(!str_contains($ci,'capital_markets_stream_all_provider_sweeper_contract.php')
    ||!str_contains($runtime,'capital_markets_all_provider_sweeper_mysql.php')){
    throw new RuntimeException('Source sweeper test not registered in CI and Runtime.');
}
echo "All-provider stream lease expiry safety contracts passed.\n";
