<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.27.0','>='),'Growth manifest must remain V0.27+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.26.0','>='),'Growth schema must remain V0.26+.');
$assert(in_array('growth.signal.feed.workspace',$manifest['contributions']['capabilities']??[],true),'Growth signal feed workspace capability missing.');

$migration='app/migrations/20260923_000092_growth_v0270_signal_feed_workspace.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.27 lifecycle migration missing.');
$sql=$read($migration);
foreach(["installed_version='0.27.0'","installed_version='0.26.0'","schema_version='0.26.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.27 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.27 migration must remain schema-neutral.');
}

$controller=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach(['GrowthSignalFeedBoundary','$this->signalFeeds->feeds(','function collectors('] as $needle){
    $assert(str_contains($controller,$needle),'Growth V0.27 SSR feed composition missing: '.$needle);
}
foreach(['createFeed(','setEnabled(','runCollector('] as $forbidden){
    $assert(!str_contains($controller,$forbidden),'Growth SSR controller must not execute feed/collector mutations: '.$forbidden);
}

$template=$read('app/Interfaces/Web/View/growth/collectors.phtml');
foreach([
    'data-growth-signal-feeds','data-growth-signal-feed-create','data-growth-signal-feed-toggle',
    'RSS / Atom Feeds','HTTPS feed URL','Signal type','Confidence'
] as $needle){
    $assert(str_contains($template,$needle),'Growth V0.27 feed workspace template missing: '.$needle);
}

$js=$read('frontend/features/growth/workspace.js');
foreach([
    "data-growth-signal-feed-create","data-growth-signal-feed-toggle",
    "'/api/v1/growth/signal-feeds'","'/api/v1/growth/signal-feeds/'",
    "'X-CSRF-Token'","'X-Idempotency-Key'"
] as $needle){
    $assert(str_contains($js,$needle),'Growth V0.27 feed workspace frontend missing: '.$needle);
}
$assert(!str_contains($js,"fetch('/growth/collectors"),'Growth V0.27 feed UI must not POST to the SSR page route.');

echo "Growth V0.27 Signal Feed Workspace architecture: OK\n";
