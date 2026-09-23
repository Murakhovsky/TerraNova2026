<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.26.0','Growth V0.26 manifest version must be 0.26.0.');
$assert(($manifest['schema_version']??null)==='0.26.0','Growth V0.26 schema version must be 0.26.0.');
foreach(['growth.signal.feed.manage','growth.signal.collector.rss_atom'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth RSS/Atom capability missing: '.$capability);
}
$migration='app/migrations/20260923_000091_growth_v0260_rss_atom_collector.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.26 migration missing.');

$sql=$read($migration);
foreach([
    'tn_growth_signal_feeds','url_hash','uq_growth_signal_feed_mapping',
    "installed_version='0.26.0'","installed_version='0.25.0'","schema_version='0.26.0'"
] as $needle){
    $assert(str_contains($sql,$needle),'Growth RSS/Atom migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_signal_feeds'"),'Growth signal feed table ownership missing.');

$feed=$read('app/Domains/Growth/Domain/GrowthSignalFeed.php');
foreach(['strtolower((string)($parts[\'scheme\']??\'\'))!==\'https\'','(int)$parts[\'port\']!==443','confidence must be between 0 and 1'] as $needle){
    $assert(str_contains($feed,$needle),'Growth SignalFeed invariant missing: '.$needle);
}

$reader=$read('app/Domains/Growth/Infrastructure/Feed/SafeHttpRssAtomFeedReader.php');
foreach([
    'ExternalCallExecutor','ExternalCallPolicy','FILTER_FLAG_NO_PRIV_RANGE','FILTER_FLAG_NO_RES_RANGE',
    'CURLOPT_RESOLVE','CURLOPT_FOLLOWLOCATION=>false','CURLOPT_MAXREDIRS=>0',
    'MAX_BODY_BYTES=2_097_152','strtolower((string)($parts[\'scheme\']??\'\'))!==\'https\'',
] as $needle){
    $assert(str_contains($reader,$needle),'Growth RSS/Atom safe transport missing: '.$needle);
}
foreach(['CURLOPT_SSL_VERIFYPEER=>false','CURLOPT_SSL_VERIFYHOST=>0'] as $forbidden){
    $assert(!str_contains($reader,$forbidden),'Growth RSS/Atom transport disables TLS verification: '.$forbidden);
}

$parser=$read('app/Domains/Growth/Infrastructure/Feed/RssAtomFeedParser.php');
foreach(['DOMDocument','DOMXPath','LIBXML_NONET','local-name()="item" or local-name()="entry"'] as $needle){
    $assert(str_contains($parser,$needle),'Growth RSS/Atom parser missing: '.$needle);
}
$assert(!str_contains($parser,'LIBXML_NOENT'),'Growth RSS/Atom parser must not enable external entity expansion.');

$collector=$read('app/Domains/Growth/Infrastructure/Collector/RssAtomSignalCollector.php');
foreach([
    'SignalCollectorInterface','GrowthSignalFeedRepositoryInterface','GrowthFeedReaderInterface',
    "return 'rss_atom'",'listEnabled(','new CollectedSignal(','sourceReference:$entry->link',
] as $needle){
    $assert(str_contains($collector,$needle),'Growth RSS/Atom collector missing: '.$needle);
}
foreach(['PDO','curl_','DOMDocument'] as $forbidden){
    $assert(!str_contains($collector,$forbidden),'Growth RSS/Atom collector bypasses its ports: '.$forbidden);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthSignalFeedService.php');
foreach([
    'GrowthSignalFeedBoundary','GrowthSignalFeedRepositoryInterface','GrowthMutationReceiptInterface',
    'create_signal_feed','enable_signal_feed','disable_signal_feed',
    'SIGNAL_FEED_CREATED','SIGNAL_FEED_ENABLED','SIGNAL_FEED_DISABLED',
] as $needle){
    $assert(str_contains($service,$needle),'Growth signal feed service missing: '.$needle);
}
foreach(['PDO','curl_','Infrastructure\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth signal feed application crossed boundary: '.$forbidden);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach(['GrowthSignalFeedBoundary','signalFeeds','createSignalFeed','enableSignalFeed','disableSignalFeed'] as $needle){
    $assert(str_contains($controller,$needle),'Growth signal feed API missing: '.$needle);
}
$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])===64,'Growth V0.26 must expose exactly 64 canonical Growth API routes.');
foreach([
    '/api/v1/growth/signal-feeds',
    '/api/v1/growth/signal-feeds/{id}/enable',
    '/api/v1/growth/signal-feeds/{id}/disable',
] as $path){
    $assert(str_contains($routes,'path: '.$path),'Growth signal feed route missing: '.$path);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthSignalFeedRepositoryInterface','MysqlGrowthSignalFeedRepository',
    'GrowthSignalFeedBoundary','GrowthSignalFeedService',
    'GrowthFeedReaderInterface','SafeHttpRssAtomFeedReader','RssAtomFeedParser','RssAtomSignalCollector',
] as $needle){
    $assert(str_contains($services,$needle),'Growth RSS/Atom DI missing: '.$needle);
}

echo "Growth V0.26 Tenant RSS/Atom Signal Collector architecture: OK\n";
