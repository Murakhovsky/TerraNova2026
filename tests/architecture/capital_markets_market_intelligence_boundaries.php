<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

$domain=$root.'/app/Domains/CapitalMarkets/Domain';
$marketData=$domain.'/MarketData';
$services=$domain.'/Service';
$contracts=$domain.'/Contract';
$application=$root.'/app/Domains/CapitalMarkets/Application';
$infrastructure=$root.'/app/Domains/CapitalMarkets/Infrastructure';

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
    $application.'/Contract/MarketDataDecoderInterface.php',
    $application.'/Contract/MarketDataNormalizerInterface.php',
    $application.'/Contract/MarketSourceRepositoryInterface.php',
    $application.'/Contract/RawMarketEventRepositoryInterface.php',
    $application.'/Contract/CanonicalMarketEventRepositoryInterface.php',
    $application.'/Contract/MarketStateRepositoryInterface.php',
    $application.'/Contract/MarketPartitionLockInterface.php',
    $application.'/Service/MarketDataNormalizer.php',
    $application.'/Service/MarketDataIngestionService.php',
    $infrastructure.'/Persistence/MySql/MysqlMarketSourceRepository.php',
    $infrastructure.'/Persistence/MySql/MysqlRawMarketEventRepository.php',
    $infrastructure.'/Persistence/MySql/MysqlCanonicalMarketEventRepository.php',
    $infrastructure.'/Persistence/MySql/MysqlMarketStateRepository.php',
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
foreach(['getSource()','getCapabilities()','supports(','resolveInstrument(','getSnapshot(','getHealth('] as $needle){
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
foreach(['venueId','instrumentId','stateVersion','quality_status','trust_status','last_event_fingerprint','mode'] as $needle){
    if(!str_contains($state,$needle))throw new RuntimeException('MarketState contract missing: '.$needle);
}

$ingestion=(string)file_get_contents($application.'/Service/MarketDataIngestionService.php');
$rawAppend=strpos($ingestion,'$this->rawEvents->append');
$decoderCall=strpos($ingestion,'$this->decoders->get');
if($rawAppend===false||$decoderCall===false||$rawAppend>$decoderCall){
    throw new RuntimeException('Raw market evidence must be persisted before provider decoding/normalization.');
}
foreach(['MarketPartitionLockInterface','TransactionManagerInterface','publishTrustTransition','UNKNOWN_INSTRUMENT'] as $needle){
    if(!str_contains($ingestion,$needle))throw new RuntimeException('Market ingestion runtime contract missing: '.$needle);
}

$normalizer=(string)file_get_contents($application.'/Service/MarketDataNormalizer.php');
foreach(['MarketInstrumentResolverInterface','UNKNOWN_INSTRUMENT','PrecisionMismatch','CanonicalMarketEvent'] as $needle){
    if(!str_contains($normalizer,$needle))throw new RuntimeException('Market-data normalizer contract missing: '.$needle);
}
foreach(['Bybit','Massive','Kraken','Binance'] as $providerLeak){
    if(str_contains($normalizer,$providerLeak))throw new RuntimeException('Provider-specific branching leaked into generic MarketDataNormalizer: '.$providerLeak);
}

foreach([
    'app/migrations/20261006_000125_capital_markets_market_sources.sql',
    'app/migrations/20261006_000126_capital_markets_market_events.sql',
    'app/migrations/20261006_000127_capital_markets_market_state.sql',
] as $migration){
    $path=$root.'/'.$migration;
    if(!is_file($path))throw new RuntimeException('Market Intelligence migration missing: '.$migration);
}
$sourceMigration=(string)file_get_contents($root.'/app/migrations/20261006_000125_capital_markets_market_sources.sql');
foreach(['tn_capital_market_data_sources','tn_capital_market_source_health','tn_capital_market_subscriptions','credentials_reference','quality_policy_json','license_profile'] as $needle){
    if(!str_contains($sourceMigration,$needle))throw new RuntimeException('Market source persistence contract missing: '.$needle);
}
if(str_contains($sourceMigration,'credentials_secret')||str_contains($sourceMigration,'api_secret')){
    throw new RuntimeException('Market source persistence must not store raw provider secrets.');
}

$eventMigration=(string)file_get_contents($root.'/app/migrations/20261006_000126_capital_markets_market_events.sql');
foreach(['tn_capital_market_raw_events','tn_capital_market_canonical_events','tn_capital_market_quality_metrics','fingerprint','data_mode'] as $needle){
    if(!str_contains($eventMigration,$needle))throw new RuntimeException('Market event persistence contract missing: '.$needle);
}
$stateMigration=(string)file_get_contents($root.'/app/migrations/20261006_000127_capital_markets_market_state.sql');
foreach(['tn_capital_market_states','tn_capital_market_reference_states','tn_capital_market_snapshots','tn_capital_market_data_gaps'] as $needle){
    if(!str_contains($stateMigration,$needle))throw new RuntimeException('Market state persistence contract missing: '.$needle);
}

$process=(string)file_get_contents($root.'/resources/processes/capital-markets-market-data-to-trusted-state.json');
foreach(['capture-raw','decode-normalize','assess-quality','persist-canonical','update-state'] as $needle){
    if(!str_contains($process,$needle))throw new RuntimeException('Market Intelligence process registry step missing: '.$needle);
}

echo "Capital Markets Market Intelligence boundaries passed.\n";
