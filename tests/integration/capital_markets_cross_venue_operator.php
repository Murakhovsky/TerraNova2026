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
    'app/Domains/CapitalMarkets/Application/Service/BStocksReferenceBboPlanner.php',
    'symfony/src/Command/CapitalMarketsReferenceBboPlanCommand.php',
    'app/Domains/CapitalMarkets/Application/Service/CrossVenueMarketSourceAdmission.php',
    'symfony/src/Command/CapitalMarketsSubscriptionPlanCommand.php',
    'app/Domains/CapitalMarkets/Application/Service/BStocksCanonicalDraftProjection.php',
    'app/Domains/CapitalMarkets/Application/Service/BStocksCanonicalDraftService.php',
    'symfony/src/Command/CapitalMarketsCanonicalDraftCommand.php',
    'app/Domains/CapitalMarkets/Application/Service/BoundedBboSweepService.php',
    'app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/MysqlBoundedBboSweepGate.php',
    'app/migrations/20261014_000142_capital_markets_bbo_poll_gate.sql',
    'symfony/src/Application/CapitalMarkets/Command/SampleBStockBboQuotes.php',
    'symfony/src/Application/CapitalMarkets/Handler/SampleBStockBboQuotesHandler.php',
    'app/Domains/CapitalMarkets/Application/Service/CrossVenuePoolProjection.php',
    'app/Domains/CapitalMarkets/Application/Service/CrossVenueActiveUniverse.php',
    'app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/CrossVenuePoolCandidateRepository.php',
    'app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/CrossVenueListingReviewRepository.php',
    'app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/CrossVenueBboAdmissionFence.php',
    'app/migrations/20261015_000143_capital_markets_listing_review.sql',
    'symfony/src/Command/CapitalMarketsInstrumentDiscoveryCommand.php',
    'symfony/src/Application/CapitalMarkets/Command/RefreshCrossVenueCandidatePool.php',
    'symfony/src/Application/CapitalMarkets/Handler/RefreshCrossVenueCandidatePoolHandler.php',
];
foreach($files as $file)$assert(is_file($root.'/'.$file),'Missing cross-venue artifact: '.$file);
$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['BinanceSpotMarketDataAdapter','BinanceMarketDataDecoder',
    'CrossVenueCatalogDiscovery','CrossVenueDiscoverySnapshotRepository',
    'CrossVenueDiscoveryController','CrossVenueQuoteSampler','CrossVenueQuoteSnapshotRepository',
    'CrossVenuePoolProjection','CrossVenuePoolCandidateRepository','CrossVenueListingReviewRepository',
    'CrossVenueBboAdmissionFence',
    'BStocksSeedFileReader','BStocksSeedImportRepository','CrossVenueSubscriptionPlanner',
    'CrossVenueActiveUniverse',
    'CrossVenueMarketSourceAdmission','BStocksReferenceBboPlanner',
    'BStocksCanonicalDraftProjection','BStocksCanonicalDraftService','BoundedBboSweepService',
    'MysqlBoundedBboSweepGate',"$".'connection: '."'@cos.database.pdo'"] as $needle){
    $assert(str_contains($services,$needle),'Missing cross-venue wiring: '.$needle);
}
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach(['/capital-markets/discovery','/capital-markets/discovery/scan',
    'CrossVenueDiscoveryController::index','CrossVenueDiscoveryController::scan',
    '/capital-markets/discovery/listing-review','CrossVenueDiscoveryController::reviewListing',
    '/capital-markets/discovery/subscription-plan','CrossVenueDiscoveryController::planPoolBbo',
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
$bboSweep=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/BoundedBboSweepService.php');
$bboPolicy=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/BoundedBboSweepPolicy.php');
$assert(str_contains($bboSweep,'MarketSourcePollingService')
    && str_contains($bboSweep,'BoundedBboSweepPolicy')
    && str_contains($bboPolicy,'BLOCKED_BUDGET')
    && str_contains($bboPolicy,'BLOCKED_MIXED_SUBSCRIPTIONS'),
    'BBO fallback must use canonical ingestion, bounded budget and typed subscriptions.');
$assert(str_contains($scheduler,'SampleBStockBboQuotes')
    && str_contains($scheduler,'capitalMarketsBboPollEnabled'),
    'Bounded BBO must be opt-in through the existing scheduler.');
$assert(str_contains($controller,'function seedCanonicalDrafts(Request $request)')
    && str_contains($controller,'CapitalMarketsCapability::InstrumentManage')
    && str_contains($controller,'canonicalCoverage')
    && str_contains($controller,'bboPollGate->recent'),
    'Canonical draft and BBO health operators must use existing tenant/RBAC protections.');
$assert(str_contains($tpl,'Пул кандидатів') && str_contains($tpl,'Стан канонічного пулу')
    && str_contains($tpl,'Стан контрольованого REST BBO'),
    'Discovery UI must surface all three instrument, canonical and quote readiness levels.');
$listingReview=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/CrossVenueListingReviewRepository.php');
$assert(str_contains($listingReview,'FOR UPDATE')
    && str_contains($listingReview,'REVIEWED_LISTING')
    && str_contains($listingReview,'APPROVE_LISTING')
    && str_contains($listingReview,'function revoke(')
    && str_contains($controller,'CapitalMarketsCapability::InstrumentManage')
    && str_contains($controller,'function reviewListing(Request $request)'),
    'Verified listing must require explicit human evidence review and concurrent-safe operator authorization.');
$poolRepo=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/CrossVenuePoolCandidateRepository.php');
$assert(str_contains($poolRepo,"'verified'=>'REVIEWED_LISTING'")
    && str_contains($poolRepo,"'fresh_after'"),
    'Unreviewed or expired listings cannot enter BBO subscription planning.');
$sourceAdmission=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/CrossVenueMarketSourceAdmission.php');
$assert(str_contains($sourceAdmission,'MarketDataProviderAvailabilityInterface')
    && str_contains($sourceAdmission,'LICENSE_PROFILE_UNDECLARED')
    && str_contains($sourceAdmission,'REST_MODE_NOT_EXPLICIT'),
    'Automatic subscription and BBO sampler must reject disallowed or WS-owned sources.');
$activeUniverse=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/CrossVenueActiveUniverse.php');
$scheduled=(string)file_get_contents($root.'/symfony/src/Application/CapitalMarkets/Handler/RefreshCrossVenueCandidatePoolHandler.php');
$manual=(string)file_get_contents($root.'/symfony/src/Command/CapitalMarketsInstrumentDiscoveryCommand.php');
$assert(str_contains($activeUniverse,'IMPORTED_MYSQL')
    && str_contains($scheduled,'CrossVenueActiveUniverse')
    && str_contains($manual,'CrossVenueActiveUniverse')
    && str_contains($controller,'$this->activeUniverse->load($org,$this->universeFile)'),
    'All manual/scheduled discovery entrypoints must use the imported tenant universe.');
$referencePlanner=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/BStocksReferenceBboPlanner.php');
$assert(str_contains($referencePlanner,'massive.stocks.v2')
    && str_contains($referencePlanner,'MarketDataAdministrationService')
    && str_contains($referencePlanner,'Reference')
    && str_contains($controller,'function planPoolBbo(Request $request)'),
    'Reference BBO must reuse audited subscriptions and require explicit source/tenant.');
echo "Cross-venue discovery integration/operator contracts passed.\n";
