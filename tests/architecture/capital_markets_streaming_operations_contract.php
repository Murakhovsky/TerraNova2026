<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$service=file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingOperationsSnapshot.php');
$cli=file_get_contents($root.'/symfony/src/Command/CapitalMarketsStreamingStatusCommand.php');
$runtime=file_get_contents($root.'/.github/workflows/runtime.yml');
if(!is_string($service)||!is_string($cli)||!is_string($runtime))throw new RuntimeException('Ops files absent.');
foreach(['organization_id=:org','source_id=:source','UTC_TIMESTAMP(6)','trust_status',
    'READ_ONLY','owner_id','LIMIT 501','max(0,'] as $x){
    if(!str_contains($service,$x))throw new RuntimeException('Unsafe streaming operator projection '.$x);
}
foreach(['AsCommand','stream:status','MysqlStreamingOperationsSnapshot','JSON_THROW_ON_ERROR'] as $x){
    if(!str_contains($cli,$x))throw new RuntimeException('Missing status CLI '.$x);
}
if(!str_contains($runtime,'capital_markets_streaming_ops_mysql.php')){
    throw new RuntimeException('MySQL operations regression not registered.');
}
echo "Streaming read-only operator snapshot contracts passed.\n";
