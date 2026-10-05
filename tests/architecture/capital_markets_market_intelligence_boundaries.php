<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

$domain=$root.'/app/Domains/CapitalMarkets/Domain';
$marketData=$domain.'/MarketData';
$services=$domain.'/Service';
$contracts=$domain.'/Contract';

foreach([$marketData,$services,$contracts] as $directory){
    if(!is_dir($directory))throw new RuntimeException('Capital Markets Market Intelligence directory missing: '.substr($directory,strlen($root)+1));
}

$required=[
    $contracts.'/MarketDataAdapterInterface.php',
    $contracts.'/StreamingMarketDataAdapterInterface.php',
    $contracts.'/HistoricalMarketDataAdapterInterface.php',
    $contracts.'/ReferenceDataAdapterInterface.php',
    $contracts.'/ConversionRateProviderInterface.php',
    $marketData.'/RawMarketEvent.php',
    $marketData.'/CanonicalMarketEvent.php',
    $marketData.'/MarketState.php',
    $marketData.'/ReferenceMarketState.php',
    $marketData.'/MarketSnapshot.php',
    $services.'/MarketDataQualityEngine.php',
    $services.'/MarketStateEngine.php',
    $services.'/OrderBookRebuilder.php',
    $services.'/MarketBackpressurePolicy.php',
    $domain.'/Value/DecimalMath.php',
];
foreach($required as $path){
    if(!is_file($path))throw new RuntimeException('Market Intelligence core file missing: '.substr($path,strlen($root)+1));
}

$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domain));
foreach($iterator as $file){
    if(!$file->isFile()||$file->getExtension()!=='php')continue;
    $source=(string)file_get_contents($file->getPathname());
    foreach(['Bybit','Massive','api.bybit','massive.com','curl_','WebSocketClient'] as $providerLeak){
        if(str_contains($source,$providerLeak)){
            throw new RuntimeException('Provider-specific implementation leaked into Capital Markets Domain: '.$providerLeak.' in '.substr($file->getPathname(),strlen($root)+1));
        }
    }
    if(preg_match('/\bfloat\b|\(float\)|floatval\s*\(/i',$source)===1){
        throw new RuntimeException('Floating-point semantics leaked into Capital Markets Domain: '.substr($file->getPathname(),strlen($root)+1));
    }
}

$adapter=(string)file_get_contents($contracts.'/MarketDataAdapterInterface.php');
foreach(['getSource()','getCapabilities()','supports(','resolveInstrument(','getSnapshot(','getHealth()'] as $needle){
    if(!str_contains($adapter,$needle))throw new RuntimeException('MarketDataAdapter contract missing: '.$needle);
}

$streaming=(string)file_get_contents($contracts.'/StreamingMarketDataAdapterInterface.php');
foreach(['connect(','disconnect(','subscribe(','unsubscribe(','isConnected(','getConnectionState(','consume('] as $needle){
    if(!str_contains($streaming,$needle))throw new RuntimeException('Streaming adapter contract missing: '.$needle);
}

$quality=(string)file_get_contents($services.'/MarketDataQualityEngine.php');
foreach(['Stale','CrossedMarket','SequenceGap','Duplicate','OutOfOrder','ReferenceMismatch','ClockUncertain'] as $needle){
    if(!str_contains($quality,$needle))throw new RuntimeException('Quality engine deterministic rule missing: '.$needle);
}
foreach(['LLM','OpenAI','Agent'] as $forbidden){
    if(str_contains($quality,$forbidden))throw new RuntimeException('Quality/trust decisions must not depend on AI: '.$forbidden);
}

$state=(string)file_get_contents($marketData.'/MarketState.php');
foreach(['venueId','instrumentId','stateVersion','quality_status','trust_status'] as $needle){
    if(!str_contains($state,$needle))throw new RuntimeException('MarketState contract missing: '.$needle);
}

echo "Capital Markets Market Intelligence boundaries passed.\n";
