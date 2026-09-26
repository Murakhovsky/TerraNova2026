<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.48.0','>='),'Growth manifest must remain V0.48+.');
$migration='app/migrations/20260926_000113_growth_v0480_market_discovery.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'V0.48 migration missing.');
foreach(['growth.market.discovery','growth.market.automated_sourcing','growth.market.monitoring','growth.market.opportunity_detection'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Missing V0.48 capability: '.$capability);
}

$sql=$read($migration);
foreach(['tn_growth_market_universes','tn_growth_market_discovery_runs','tn_growth_market_memberships','trigger_signal_id',"installed_version='0.48.0'"] as $needle){
    $assert(str_contains($sql,$needle),'V0.48 migration missing: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthMarketDiscoveryService.php');
foreach([
    'discoverAccount(','captureAccountSnapshot(','scoreAccount(','listSignalsBySubject(','detectCandidate(',
    'claimOpportunityTrigger(','MARKET_ACCOUNT_SOURCED','MARKET_OPPORTUNITY_DETECTED',
] as $needle)$assert(str_contains($service,$needle),'Market discovery runtime missing: '.$needle);
$assert(strpos($service,'scoreAccount(')<strpos($service,'listSignalsBySubject('),'ICP fit must precede Opportunity signal admission.');
$assert(strpos($service,'listSignalsBySubject(')<strpos($service,'detectCandidate('),'Opportunity detection must require canonical Signal evidence.');

$source=$read('app/Domains/Growth/Infrastructure/Market/CredentialedJsonGrowthMarketSource.php');
foreach(['CredentialVaultInterface','ExternalCallExecutor','FILTER_FLAG_NO_PRIV_RANGE','CURLOPT_FOLLOWLOCATION=>false','source_references','next_cursor'] as $needle){
    $assert(str_contains($source,$needle),'Credentialed market source missing safety/runtime contract: '.$needle);
}

$consumer=$read('app/Domains/Growth/Automation/Event/GrowthMarketOpportunityConsumer.php');
foreach(['DurableEventConsumerInterface','growth.market-opportunity.v1','SIGNAL_DETECTED',"'account'",'considerSignal('] as $needle){
    $assert(str_contains($consumer,$needle),'Market Signal consumer missing: '.$needle);
}

$scheduler=$read('symfony/src/Scheduler/CosScheduleProvider.php');
$services=$read('symfony/config/services.yaml');
$messenger=$read('symfony/config/packages/messenger.yaml');
foreach(['RunGrowthMarketDiscoveryCommand','growthMarketDiscoveryEnabled','COS_GROWTH_MARKET_SCHEDULER_ENABLED'] as $needle){
    $assert(str_contains($scheduler.$services,$needle),'Market scheduler missing: '.$needle);
}
$assert(str_contains($messenger,'RunGrowthMarketDiscoveryCommand'),'Market scheduler Messenger route missing.');

$routes=$read('symfony/config/routes.yaml');
$api=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
$page=$read('symfony/src/Web/Growth/GrowthPageController.php');
$web=$read('symfony/src/Web/Experience/Extension/Provider/GrowthWebProvider.php');
$template=$read('app/Interfaces/Web/View/growth/market.phtml');
$js=$read('frontend/features/growth/workspace.js');
foreach(['/growth/market','/api/v1/growth/market/universes','createMarketUniverse','runMarketUniverse'] as $needle){
    $assert(str_contains($routes.$api,$needle),'Market API/page surface missing: '.$needle);
}
foreach(['function market(','growth/market','growth-market'] as $needle)$assert(str_contains($page.$web,$needle),'Market workspace wiring missing: '.$needle);
foreach(['data-growth-market','Market Universe','Opportunity creation waits for an observed Signal'] as $needle)$assert(str_contains($template,$needle),'Market workspace missing: '.$needle);
$assert(str_contains($js,'initGrowthMarket'),'Market workspace mutation runtime missing.');

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(["'tn_growth_market_universes'","'tn_growth_market_discovery_runs'","'tn_growth_market_memberships'"] as $needle){
    $assert(str_contains($ownership,$needle),'V0.48 table ownership missing: '.$needle);
}

$readme=$read('app/Domains/Growth/README.md');
foreach(['V0.48 — Market Discovery & Automated Account Sourcing','Market Universe → Company Discovery → Enrichment → ICP Match → Monitoring → Opportunity','Signal != Opportunity'] as $needle){
    $assert(str_contains($readme,$needle),'V0.48 documentation missing: '.$needle);
}

echo "Growth V0.48 Market Discovery architecture: OK\n";
