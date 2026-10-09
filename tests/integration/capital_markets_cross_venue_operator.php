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
    'symfony/templates/experience/capital_markets/discovery.html.twig',
    'app/migrations/20261009_000139_capital_markets_cross_venue_discovery.sql',
];
foreach($files as $file)$assert(is_file($root.'/'.$file),'Missing cross-venue artifact: '.$file);
$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['BinanceSpotMarketDataAdapter','BinanceMarketDataDecoder',
    'CrossVenueCatalogDiscovery','CrossVenueDiscoverySnapshotRepository',
    'CrossVenueDiscoveryController',"$".'connection: '."'@cos.database.pdo'"] as $needle){
    $assert(str_contains($services,$needle),'Missing cross-venue wiring: '.$needle);
}
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach(['/capital-markets/discovery','/capital-markets/discovery/scan',
    'CrossVenueDiscoveryController::index','CrossVenueDiscoveryController::scan'] as $needle){
    $assert(str_contains($routes,$needle),'Missing discovery route: '.$needle);
}
$controller=(string)file_get_contents($root.'/symfony/src/Web/CapitalMarkets/CrossVenueDiscoveryController.php');
foreach(['CapitalMarketsCapability::MarketDataManage','CapitalMarketsCapability::MarketDataView',
    '$this->csrf->isValid($request)','$this->snapshots->mayScan($org)'] as $needle){
    $assert(str_contains($controller,$needle),'Unsafe discovery operator boundary: '.$needle);
}
$availability=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/MarketData/CapitalMarketsMarketDataProviderAvailability.php');
$assert(str_contains($availability,'MarketDataBinance'),'Binance source must remain behind tenant flag.');
$tpl=(string)file_get_contents($root.'/symfony/templates/experience/capital_markets/discovery.html.twig');
$assert(str_contains($tpl,'економічн') || str_contains($tpl,'Ідентичність'),
    'UI must explain economic relationship uncertainty.');
echo "Cross-venue discovery integration/operator contracts passed.\n";
