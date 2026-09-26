<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.29.0','>='),'Growth manifest must remain V0.29+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.28.0','>='),'Growth schema must remain V0.28+.');
$assert(in_array('growth.signal.json_source.workspace',$manifest['contributions']['capabilities']??[],true),'Growth JSON source workspace capability is missing.');

$migration='app/migrations/20260924_000094_growth_v0290_json_signal_source_workspace.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.29 lifecycle migration is missing.');
$sql=$read($migration);
foreach(["installed_version='0.29.0'","installed_version='0.28.0'","schema_version='0.28.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.29 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.29 migration must remain schema-neutral.');
}

$controller=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach([
    'GrowthJsonSignalSourceBoundary',
    "'json_sources'=>\$this->jsonSignalSources->sources(",
    'function collectors(',
] as $needle){
    $assert(str_contains($controller,$needle),'Growth V0.29 SSR JSON source composition missing: '.$needle);
}
foreach(['jsonSignalSources->createSource(','jsonSignalSources->setEnabled('] as $forbidden){
    $assert(!str_contains($controller,$forbidden),'Growth SSR controller must not execute JSON source mutations: '.$forbidden);
}

$template=$read('app/Interfaces/Web/View/growth/collectors.phtml');
foreach([
    'data-growth-json-signal-sources','data-growth-json-source-create','data-growth-json-source-toggle',
    'JSON API Sources','Credential reference','credential_configured','Bearer token','X-* API key header',
] as $needle){
    $assert(str_contains($template,$needle),'Growth V0.29 JSON source workspace template missing: '.$needle);
}
foreach([
    '$source[\'credential_reference\']',
    '$source["credential_reference"]',
    'credential_reference_hash',
] as $forbidden){
    $assert(!str_contains($template,$forbidden),'Growth V0.29 workspace must not render stored credential reference material: '.$forbidden);
}

$js=$read('frontend/features/growth/workspace.js');
foreach([
    "data-growth-json-source-create","data-growth-json-source-toggle",
    "'/api/v1/growth/json-signal-sources'","'/api/v1/growth/json-signal-sources/'",
    "values.get('credential_reference')","authMode==='api_key_header'",
    "'X-CSRF-Token'","'X-Idempotency-Key'",
] as $needle){
    $assert(str_contains($js,$needle),'Growth V0.29 JSON source frontend missing: '.$needle);
}
$assert(!str_contains($js,"fetch('/growth/collectors"),'Growth V0.29 JSON source UI must not POST to the SSR page route.');

$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])===68,'Growth V0.29 must preserve the 68-route V0.28 API surface.');

echo "Growth V0.29 Credentialed Source Workspace architecture: OK\n";
