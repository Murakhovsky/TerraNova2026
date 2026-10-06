<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

$required=[
    'app/Domains/CapitalMarkets/Application/Service/MarketDataAdministrationService.php',
    'symfony/src/Http/Api/V1/Controller/CapitalMarketsMarketDataController.php',
    'symfony/src/Web/CapitalMarkets/CapitalMarketsPageController.php',
    'symfony/templates/experience/capital_markets/workspace.html.twig',
    'resources/experience/pages/capital_markets/foundation.yaml',
];
foreach($required as $relative){
    if(!is_file($root.'/'.$relative))throw new RuntimeException('Capital Markets operator file missing: '.$relative);
}

$admin=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/MarketDataAdministrationService.php');
foreach([
    'MarketSourceRepositoryInterface','MarketSubscriptionRepositoryInterface','MarketStateRepositoryInterface',
    'MarketDataAdapterRegistry','MarketSourcePollingService','CapitalMarketsAuditTrail','TransactionManagerInterface',
    'MarketDataSourceCreated','MarketDataSourceEnabled','MarketDataSourceDisabled',
    'MarketDataSubscriptionSaved','MarketDataPollTriggered',
] as $needle){
    if(!str_contains($admin,$needle))throw new RuntimeException('Market Data administration contract missing: '.$needle);
}
if(!str_contains($admin,"\$adapterType,\n                false,")){
    throw new RuntimeException('New market-data sources must be created disabled.');
}
foreach(['api_key','secret','password','token'] as $forbiddenField){
    if(str_contains($admin,"'".$forbiddenField."'=>")||str_contains($admin,'["'.$forbiddenField.'"]')){
        throw new RuntimeException('Market Data administration must not accept raw credential material: '.$forbiddenField);
    }
}
foreach(['credentials_reference','credentials_configured'] as $needle){
    if(!str_contains($admin,$needle))throw new RuntimeException('Credential-reference operator contract missing: '.$needle);
}

$api=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/CapitalMarketsMarketDataController.php');
foreach([
    'MarketDataSourceManage','MarketDataManage','MarketDataView',
    'CapitalMarketsFeatureFlag::MarketData','SessionCsrfValidator','isValid($request)',
    'TenantContextProviderInterface','ActiveModuleResolver',
] as $needle){
    if(!str_contains($api,$needle))throw new RuntimeException('Market Data API safety gate missing: '.$needle);
}

$page=(string)file_get_contents($root.'/symfony/src/Web/CapitalMarkets/CapitalMarketsPageController.php');
foreach([
    'public function marketData(','createMarketDataSource(','enableMarketDataSource(','disableMarketDataSource(',
    'createMarketDataSubscription(','pollMarketDataSource(','canManageMarketData','canManageMarketDataSources',
] as $needle){
    if(!str_contains($page,$needle))throw new RuntimeException('Market Data workspace controller contract missing: '.$needle);
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
    '/capital-markets/market-data',
    '/capital-markets/market-data/sources/{id}/poll',
    '/api/v1/capital-markets/market-data',
    '/api/v1/capital-markets/market-data/sources/{id}/subscriptions',
    'CapitalMarketsMarketDataController::poll',
] as $needle){
    if(!str_contains($routes,$needle))throw new RuntimeException('Market Data route missing: '.$needle);
}

$template=(string)file_get_contents($root.'/symfony/templates/experience/capital_markets/workspace.html.twig');
foreach([
    "cmView == 'market_data'",'Create disabled source','Sources and health','Current trading MarketState',
    'Reference MarketState','Poll now','credentials_reference',
] as $needle){
    if(!str_contains($template,$needle))throw new RuntimeException('Market Data workspace UI contract missing: '.$needle);
}
foreach(['Place order','/orders','name="side"','>Buy<','>Sell<'] as $forbidden){
    if(str_contains($template,$forbidden))throw new RuntimeException('Execution control leaked into Market Data workspace: '.$forbidden);
}

$provider=(string)file_get_contents($root.'/symfony/src/Web/Experience/Extension/Provider/CapitalMarketsWebProvider.php');
foreach(['capital-markets-market-data','/capital-markets/market-data','capital_markets.market_data'] as $needle){
    if(!str_contains($provider,$needle))throw new RuntimeException('Market Data web extension missing: '.$needle);
}

$pageContract=(string)file_get_contents($root.'/resources/experience/pages/capital_markets/foundation.yaml');
foreach(['id: capital_markets.market_data','capability: capital_markets.market_data.view','status: IMPLEMENTED'] as $needle){
    if(!str_contains($pageContract,$needle))throw new RuntimeException('Market Data Experience Page Contract missing: '.$needle);
}

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['MarketDataAdministrationService','CapitalMarketsMarketDataController'] as $needle){
    if(!str_contains($services,$needle))throw new RuntimeException('Market Data operator DI wiring missing: '.$needle);
}

echo "Capital Markets Market Intelligence operator boundaries passed.\n";
