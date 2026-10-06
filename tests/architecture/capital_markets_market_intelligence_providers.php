<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

$required=[
    'app/Domains/CapitalMarkets/Domain/MarketData/MarketDataInstrumentTarget.php',
    'app/Domains/CapitalMarkets/Application/Contract/MarketJsonHttpClientInterface.php',
    'app/Domains/CapitalMarkets/Application/Contract/MarketDataProviderAvailabilityInterface.php',
    'app/Domains/CapitalMarkets/Application/Contract/MarketDataIngestionInterface.php',
    'app/Domains/CapitalMarkets/Application/Service/MarketDataAdapterRegistry.php',
    'app/Domains/CapitalMarkets/Application/Service/MarketSourcePollingService.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Http/SafeMarketJsonHttpClient.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Security/MarketSourceCredentialResolver.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/VenueMarketStatusResolver.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Bybit/BybitOrderBookPayloadParser.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Bybit/BybitSpotMarketDataAdapter.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Bybit/BybitMarketDataDecoder.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Kraken/KrakenSpotPayloadParser.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Kraken/KrakenSpotMarketDataAdapter.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Kraken/KrakenMarketDataDecoder.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Massive/MassiveStocksReferenceAdapter.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Massive/MassiveMarketDataDecoder.php',
    'symfony/src/Command/CapitalMarketsMarketDataPollCommand.php',
];
foreach($required as $relative){
    if(!is_file($root.'/'.$relative))throw new RuntimeException('Market provider runtime file missing: '.$relative);
}

$adapter=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/Contract/MarketDataAdapterInterface.php');
foreach(['string $organizationId','MarketDataInstrumentTarget','array $capabilities','getSnapshot(','getHealth('] as $needle){
    if(!str_contains($adapter,$needle))throw new RuntimeException('Tenant-safe market adapter contract missing: '.$needle);
}

$http=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Http/SafeMarketJsonHttpClient.php');
foreach([
    'ExternalCallExecutor','ExternalCallPolicy','allowedHosts','FILTER_FLAG_NO_PRIV_RANGE','FILTER_FLAG_NO_RES_RANGE',
    'CURLOPT_FOLLOWLOCATION=>false','CURLOPT_MAXREDIRS=>0','CURLOPT_RESOLVE','JSON_THROW_ON_ERROR',
] as $needle){
    if(!str_contains($http,$needle))throw new RuntimeException('Safe market HTTP invariant missing: '.$needle);
}
foreach(['CURLOPT_FOLLOWLOCATION=>true','http://','Authorization: Bearer'] as $forbidden){
    if(str_contains($http,$forbidden))throw new RuntimeException('Generic market HTTP client contains forbidden behavior: '.$forbidden);
}

$credentials=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Security/MarketSourceCredentialResolver.php');
foreach(['CredentialVaultInterface','OrganizationId::fromString','credentialsReference',"'api_key'","'token'"] as $needle){
    if(!str_contains($credentials,$needle))throw new RuntimeException('Market credential resolver invariant missing: '.$needle);
}
foreach(['getenv(','file_get_contents(','error_log(','var_dump('] as $forbidden){
    if(str_contains($credentials,$forbidden))throw new RuntimeException('Market credential resolver bypasses Credential Vault: '.$forbidden);
}

$bybit=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Bybit/BybitSpotMarketDataAdapter.php');
foreach(['/v5/market/tickers','/v5/market/orderbook','category=spot','MarketDataCapability::Bbo','MarketDataCapability::Volume','MarketDataCapability::OrderBook','requested($capabilities','bybit.spot.ticker.bbo','bybit.spot.ticker.volume','bybit.spot.orderbook.snapshot','market_status'] as $needle){
    if(!str_contains($bybit,$needle))throw new RuntimeException('Bybit REST adapter contract missing: '.$needle);
}

$kraken=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Kraken/KrakenSpotMarketDataAdapter.php');
foreach(['/0/public/Ticker','/0/public/Depth','MarketDataCapability::Bbo','MarketDataCapability::Volume','MarketDataCapability::OrderBook','kraken.spot.ticker.bbo','kraken.spot.depth.snapshot','market_status'] as $needle){
    if(!str_contains($kraken,$needle))throw new RuntimeException('Kraken REST adapter contract missing: '.$needle);
}
foreach(['Authorization:','credentialsReference','CredentialVaultInterface'] as $forbidden){
    if(str_contains($kraken,$forbidden))throw new RuntimeException('Kraken public market-data adapter must not require credentials: '.$forbidden);
}

$status=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/VenueMarketStatusResolver.php');
foreach(['always_open','market_hours','market_hours_timezone','MarketStatus::Unknown','MarketStatus::Open','MarketStatus::Closed'] as $needle){
    if(!str_contains($status,$needle))throw new RuntimeException('Venue market-status resolver contract missing: '.$needle);
}

$massive=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Massive/MassiveStocksReferenceAdapter.php');
foreach(['/v2/last/nbbo/','Authorization: Bearer ','credentials->apiKey','MarketDataMode::Delayed','metadata.data_mode'] as $needle){
    if(!str_contains($massive,$needle))throw new RuntimeException('Massive REST adapter contract missing: '.$needle);
}
foreach(['?apiKey=','&apiKey=','api_key='] as $forbidden){
    if(str_contains($massive,$forbidden))throw new RuntimeException('Massive credential leaked into query-string construction: '.$forbidden);
}

$massiveParser=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Massive/MassiveQuotePayloadParser.php');
foreach(['numberField','integerField',"'p'","'P'","'q'","'t'"] as $needle){
    if(!str_contains($massiveParser,$needle))throw new RuntimeException('Massive lossless quote parsing contract missing: '.$needle);
}

$providerRoot=$root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData';
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($providerRoot));
foreach($iterator as $file){
    if(!$file->isFile()||$file->getExtension()!=='php')continue;
    $source=(string)file_get_contents($file->getPathname());
    if(preg_match('/\(float\)|floatval\s*\(|:\s*float\b|\bfloat\s+\$/i',$source)===1){
        throw new RuntimeException('Binary float semantics leaked into Capital Markets provider runtime: '.substr($file->getPathname(),strlen($root)+1));
    }
}

$poller=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/MarketSourcePollingService.php');
foreach(['MarketDataAdapterRegistry','MarketDataProviderAvailabilityInterface','MarketDataIngestionInterface','MarketConnectionState::Active','getSnapshot('] as $needle){
    if(!str_contains($poller,$needle))throw new RuntimeException('Market source polling invariant missing: '.$needle);
}

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach([
    'SafeMarketJsonHttpClient','BybitSpotMarketDataAdapter','BybitMarketDataDecoder',
    'MassiveStocksReferenceAdapter','MassiveMarketDataDecoder','MarketDataAdapterRegistry',
    'MarketDataDecoderRegistry','MarketDataProviderAvailabilityInterface','MarketSourcePollingService',
] as $needle){
    if(!str_contains($services,$needle))throw new RuntimeException('Provider service wiring missing: '.$needle);
}

$command=(string)file_get_contents($root.'/symfony/src/Command/CapitalMarketsMarketDataPollCommand.php');
foreach(['cos:capital-markets:market-data:poll','MarketSourcePollingService','MarketSourceId::fromString'] as $needle){
    if(!str_contains($command,$needle))throw new RuntimeException('Market polling CLI contract missing: '.$needle);
}

$migration=(string)file_get_contents($root.'/app/migrations/20261006_000127_capital_markets_market_state.sql');
if(!str_contains($migration,"'capital_markets.market_data.streaming.enabled','Capital Markets streaming market-data runtime',0,0")){
    throw new RuntimeException('Market-data streaming feature flag must remain disabled before WebSocket runtime exists.');
}

echo "Capital Markets provider adapters boundaries passed.\n";
