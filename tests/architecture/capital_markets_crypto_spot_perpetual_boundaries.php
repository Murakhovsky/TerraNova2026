<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$capitalMarkets=$root.'/app/Domains/CapitalMarkets';
$forbidden=[
    'CryptoOpportunityEngine','CryptoRiskEngine','CryptoPortfolio','CryptoLedger',
    'CryptoExecutionEngine','CryptoMarketDataEngine',
];

$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($capitalMarkets,FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){
    if(!$file->isFile()||$file->getExtension()!=='php')continue;
    $relative=str_replace($root.'/','',$file->getPathname());
    $content=(string)file_get_contents($file->getPathname());
    foreach($forbidden as $name){
        $assert(!str_contains($relative,$name)&&!preg_match('/\b(class|interface|trait)\s+'.preg_quote($name,'/').'\b/',$content),
            'VS2 must extend Capital Markets Core instead of creating '.$name);
    }
}

$requiredCore=[
    'Domain/Instrument/PerpetualProfile.php',
    'Domain/Instrument/SpotProfile.php',
    'Domain/MarketData/FundingRateObservation.php',
    'Domain/MarketData/BasisObservation.php',
    'Domain/MarketData/SpotPerpetualMarketState.php',
    'Domain/Opportunity/Opportunity.php',
    'Domain/Opportunity/RelativeValueCandidate.php',
    'Domain/Execution/ExecutionGroup.php',
    'Domain/Portfolio/HedgeGroup.php',
    'Domain/Service/RelativeValueRiskEvaluator.php',
    'Domain/Service/RelativeValueEconomicsCalculator.php',
    'Domain/Service/RelativeValuePerformanceEngine.php',
];
foreach($requiredCore as $path)$assert(is_file($capitalMarkets.'/'.$path),'Missing VS2 Core extension: '.$path);

$hypothesis=(string)file_get_contents($capitalMarkets.'/Domain/Opportunity/HypothesisCode.php');
foreach(["'H4'","'H5'","'H6'"] as $code)$assert(str_contains($hypothesis,$code),'Missing crypto hypothesis '.$code);

$bybit=(string)file_get_contents($capitalMarkets.'/Infrastructure/MarketData/Adapter/Bybit/BybitPerpetualMarketDataAdapter.php');
foreach(['category=linear','instruments-info','funding/history','DerivativesMetadata','getFundingHistory'] as $needle){
    $assert(str_contains($bybit,$needle),'Bybit perpetual adapter invariant missing: '.$needle);
}
$assert(!preg_match('/fundingIntervalSeconds\s*=\s*28800|8\s*\*\s*60\s*\*\s*60/',$bybit),
    'Bybit funding interval must not be hardcoded to 8 hours.');

$okx=(string)file_get_contents($capitalMarkets.'/Infrastructure/MarketData/Adapter/Okx/OkxPerpetualMarketDataDecoder.php');
foreach(['ctVal','ctMult','contractsToUnderlying','funding_interval_seconds'] as $needle){
    $assert(str_contains($okx,$needle),'OKX contract normalization invariant missing: '.$needle);
}

$opportunity=(string)file_get_contents($capitalMarkets.'/Domain/Opportunity/Opportunity.php');
$assert(str_contains($opportunity,'OpportunityCandidateInterface'),'Opportunity must accept shared candidate abstraction.');
$assert(!str_contains($opportunity,'public SpreadCandidate $candidate'),'Opportunity must not be hard-wired to SpreadCandidate.');

$marketState=(string)file_get_contents($capitalMarkets.'/Domain/MarketData/MarketState.php');
foreach(['fundingRate','openInterest','markPrice','indexPrice'] as $needle){
    $assert(str_contains($marketState,$needle),'MarketState must retain derivative scalar state: '.$needle);
}

$hydrator=(string)file_get_contents($capitalMarkets.'/Infrastructure/Persistence/MySql/MarketDataHydrator.php');
foreach(['MarketEventType::InstrumentMetadata','funding_rate','open_interest','mark_price','index_price'] as $needle){
    $assert(str_contains($hydrator,$needle),'Persisted derivative MarketState hydration missing: '.$needle);
}

$migration=(string)file_get_contents($root.'/app/migrations/20261006_000132_capital_markets_crypto_spot_perpetual.sql');
foreach([
    "hypothesis IN ('H1','H2','H4','H5','H6')",
    "'FUNDING','PERFORMANCE'",
    'tn_capital_market_funding_observations',
    'tn_capital_market_basis_observations',
    'tn_capital_market_funding_settlements',
    'tn_capital_market_hedge_groups',
] as $needle)$assert(str_contains($migration,$needle),'VS2 migration invariant missing: '.$needle);

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
    '/capital-markets/crypto-spot-perpetual',
    '/api/v1/capital-markets/crypto-spot-perpetual',
    '/api/v1/capital-markets/crypto-spot-perpetual/scan/spot-perp',
    '/api/v1/capital-markets/crypto-spot-perpetual/scan/cross-venue-funding',
    '/api/v1/capital-markets/crypto-spot-perpetual/opportunities/{id}/paper-execute',
    '/api/v1/capital-markets/crypto-spot-perpetual/executions/{id}/close',
    '/api/v1/capital-markets/crypto-spot-perpetual/funding',
    '/api/v1/capital-markets/crypto-spot-perpetual/funding-settlements',
    '/api/v1/capital-markets/crypto-spot-perpetual/basis',
    '/api/v1/capital-markets/crypto-spot-perpetual/hedges/{id}',
] as $route)$assert(str_contains($routes,$route),'VS2 runtime route missing: '.$route);

$apiController=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/CapitalMarketsCryptoSpotPerpetualController.php');
foreach(['scanSpotPerp','scanCrossVenueFunding','executePaper','closeExecution','CryptoSpotPerpetual'] as $needle){
    $assert(str_contains($apiController,$needle),'VS2 API contract missing: '.$needle);
}

$webController=(string)file_get_contents($root.'/symfony/src/Web/CapitalMarkets/CapitalMarketsPageController.php');
$assert(str_contains($webController,'cryptoSpotPerpetual'),'VS2 workspace controller is missing.');
$webProvider=(string)file_get_contents($root.'/symfony/src/Web/Experience/Extension/Provider/CapitalMarketsWebProvider.php');
$assert(str_contains($webProvider,'/capital-markets/crypto-spot-perpetual'),'VS2 workspace navigation is missing.');
$template=(string)file_get_contents($root.'/symfony/templates/experience/capital_markets/workspace.html.twig');
$assert(str_contains($template,"cmView == 'crypto_spot_perp'"),'VS2 workspace template is missing.');

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach([
    'BybitPerpetualMarketDataAdapter','OkxPerpetualMarketDataAdapter',
    'CryptoSpotPerpetualVerticalSliceService','RelativeValuePaperExecutionService',
    'RelativeValuePositionLifecycleService','FundingSettlementService','DerivativeResearchHistoryService',
] as $needle)$assert(str_contains($services,$needle),'VS2 runtime service wiring missing: '.$needle);

$module=require $capitalMarkets.'/module.php';
$assert(($module['version']??null)==='0.8.0','Capital Markets module version must be 0.8.0 after Research Lab pack.');
$assert(in_array('app/migrations/20261006_000132_capital_markets_crypto_spot_perpetual.sql',$module['contributions']['migration_files']??[],true),
    'VS2 migration must be module-owned.');

echo "Capital Markets Crypto Spot/Perpetual architecture boundaries passed.\n";
