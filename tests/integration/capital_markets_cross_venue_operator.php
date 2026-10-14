<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$assert=static function(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
};
$files=[
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Binance/BinanceSpotMarketDataAdapter.php',
    'app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Binance/BinanceMarketDataDecoder.php',
    'app/Domains/CapitalMarkets/Application/Service/CrossVenueCatalogDiscovery.php',
    'app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/CrossVenueDiscoverySnapshotRepository.php',
    'symfony/src/Web/CapitalMarkets/CrossVenueDiscoveryController.php',
    'app/Domains/CapitalMarkets/Application/Service/CrossVenueQuoteSampler.php',
    'app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/CrossVenueQuoteSnapshotRepository.php',
    'app/migrations/20261009_000140_capital_markets_cross_venue_quotes.sql',
    'resources/capital-markets/universes/binance-bstocks-2026-09-27.csv',
    'symfony/templates/experience/capital_markets/discovery.html.twig',
    'app/migrations/20261009_000139_capital_markets_cross_venue_discovery.sql',
    'app/migrations/20261014_000140_capital_markets_candidate_pool.sql',
    'app/migrations/20261014_000141_capital_markets_seed_evidence.sql',
    'app/Domains/CapitalMarkets/Application/Service/BStocksSeedFileReader.php',
    'app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/BStocksSeedImportRepository.php',
    'symfony/src/Command/CapitalMarketsSeedImportCommand.php',
    'app/Domains/CapitalMarkets/Application/Service/CrossVenueSubscriptionPlanner.php',
    'symfony/src/Command/CapitalMarketsSubscriptionPlanCommand.php',
    'app/Domains/CapitalMarkets/Application/Service/BStocksCanonicalDraftProjection.php',
    'app/Domains/CapitalMarkets/Application/Service/BStocksCanonicalDraftService.php',
    'symfony/src/Command/CapitalMarketsCanonicalDraftCommand.php',
    'app/Domains/CapitalMarkets/Application/Service/CrossVenuePoolProjection.php',
    'app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/CrossVenuePoolCandidateRepository.php',
    'symfony/src/Command/CapitalMarketsInstrumentDiscoveryCommand.php',
    'symfony/src/Application/CapitalMarkets/Command/RefreshCrossVenueCandidatePool.php',
    'symfony/src/Application/CapitalMarkets/Handler/RefreshCrossVenueCandidatePoolHandler.php',
];
foreach($files as $file)$assert(is_file($root.'/'.$file),'Missing cross-venue artifact: '.$file);
$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['BinanceSpotMarketDataAdapter','BinanceMarketDataDecoder',
    'CrossVenueCatalogDiscovery','CrossVenueDiscoverySnapshotRepository',
    'CrossVenueDiscoveryController','CrossVenueQuoteSampler','CrossVenueQuoteSnapshotRepository',
    'CrossVenuePoolProjection','CrossVenuePoolCandidateRepository',
    'BStocksSeedFileReader','BStocksSeedImportRepository','CrossVenueSubscriptionPlanner',
    'BStocksCanonicalDraftProjection','BStocksCanonicalDraftService',"$".'connection: '."'@cos.database.pdo'"] as $needle){
    $assert(str_contains($services,$needle),'Missing cross-venue wiring: '.$needle);
}
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach(['/capital-markets/discovery','/capital-markets/discovery/scan',
    'CrossVenueDiscoveryController::index','CrossVenueDiscoveryController::scan',
    '/capital-markets/discovery/import','CrossVenueDiscoveryController::importSeed',
    '/capital-markets/discovery/canonical-drafts','CrossVenueDiscoveryController::seedCanonicalDrafts',
    '/capital-markets/discovery/quotes','CrossVenueDiscoveryController::observeQuotes'] as $needle){
    $assert(str_contains($routes,$needle),'Missing discovery route: '.$needle);
}
$controller=(string)file_get_contents($root.'/symfony/src/Web/CapitalMarkets/CrossVenueDiscoveryController.php');
foreach(['CapitalMarketsCapability::MarketDataManage','CapitalMarketsCapability::MarketDataView',
    '$this->csrf->isValid($request)','$this->snapshots->reserveScan($org,$actor)'] as $needle){
    $assert(str_contains($controller,$needle),'Unsafe discovery operator boundary: '.$needle);
}
$availability=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/CapitalMarketsMarketDataProviderAvailability.php');
$assert(str_contains($availability,'MarketDataBinance'),'Binance source must remain behind tenant flag.');
$tpl=(string)file_get_contents($root.'/symfony/templates/experience/capital_markets/discovery.html.twig');
$assert(str_contains($tpl,'економічн') || str_contains($tpl,'Ідентичність'),
    'UI must explain economic relationship uncertainty.');
$reservation=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/CrossVenueDiscoverySnapshotRepository.php');
$assert(str_contains($reservation,'UPDATE tn_capital_market_discovery_scan_gates')
    && str_contains($reservation,'TIMESTAMPDIFF(SECOND,last_attempt_at,UTC_TIMESTAMP(6))>=:cooldown'),
    'Operator provider cooldown must use database-atomic reservation.');
$docker=(string)file_get_contents($root.'/docker/symfony/php/Dockerfile');
$assert(str_contains($docker,'COPY resources/capital-markets/ /var/www/html/resources/capital-markets/'),
    'Production Docker runtime must include historical discovery universe.');
$assert(str_contains($controller, '$this->quotes->reserve($org,$actor)'),
    'Quote refresh must have its own atomic tenant cooldown.');
$assert(str_contains($controller, '$this->poolCandidates->reconcile($org,$snapshot)'),
    'Discovery must persist candidate pool observations without approving canonical relationships.');
$scheduler=(string)file_get_contents($root.'/symfony/src/Scheduler/CosScheduleProvider.php');
$assert(str_contains($scheduler,'RefreshCrossVenueCandidatePool') && str_contains($scheduler,'capitalMarketsDiscoveryEnabled'),
    'Scheduled discovery must be gated, tenant-scoped and use the existing scheduler.');
$handler=(string)file_get_contents($root.'/symfony/src/Application/CapitalMarkets/Handler/RefreshCrossVenueCandidatePoolHandler.php');
$assert(str_contains($handler,'reserveScan') && str_contains($handler,'reconcile'),
    'Scheduled discovery must use atomic cooldown and candidate reconciliation.');
$assert(str_contains($controller,'function importSeed(Request $request)')
    && str_contains($controller,"$".'this->seedImports->apply($org,$actor,$seed)')
    && str_contains($controller,"$".'this->seedImports->preview($org,$seed)'),
    'Operator import must support both preview and committed evidence.');
$planner=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/CrossVenueSubscriptionPlanner.php');
$assert(str_contains($planner,'InstrumentStatus::Active')
    && str_contains($planner,'VenueInstrumentStatus::Active')
    && str_contains($planner,'BLOCKED_AMBIGUOUS_MAPPING')
    && str_contains($planner,'createSubscription'),
    'BBO planner must use existing audited source and canonical identity gates.');
echo "Cross-venue discovery integration/operator contracts passed.\n";
