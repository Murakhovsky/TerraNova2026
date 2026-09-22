<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.12.0','Growth V0.12 manifest version must be 0.12.0.');
$assert(($manifest['schema_version']??null)==='0.8.0','Growth V0.12 must keep schema version 0.8.0.');
$assert(in_array('growth.signal.operations',$manifest['contributions']['capabilities']??[],true),'Growth signal operations capability is missing.');

$migration='app/migrations/20260922_000076_growth_v0120_signal_operations.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.12 lifecycle migration is missing.');
$sql=$read($migration);
foreach(["installed_version='0.12.0'","installed_version='0.11.0'","schema_version='0.8.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.12 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.12 migration must remain schema-neutral: '.$forbidden);
}

$contract=$read('app/Domains/Growth/Application/Contract/GrowthWorkspaceReadModelInterface.php');
foreach(['function signals(','function collectorRuns('] as $needle){
    $assert(str_contains($contract,$needle),'Growth Workspace read contract missing: '.$needle);
}

$readModel=$read('app/Domains/Growth/Infrastructure/ReadModel/MySql/MysqlGrowthWorkspaceReadModel.php');
foreach([
    'tn_growth_signals','tn_growth_signal_source_receipts','tn_growth_signal_collector_runs',
    'candidate_count','collector_name','duplicate_count','failed_count',
] as $needle){
    $assert(str_contains($readModel,$needle),'Growth Signal Operations read model missing: '.$needle);
}
foreach(['INSERT INTO','UPDATE tn_growth','DELETE FROM tn_growth'] as $forbidden){
    $assert(!str_contains($readModel,$forbidden),'Growth Signal Operations read model must remain read-only: '.$forbidden);
}

$provider=$read('symfony/src/Web/Experience/Extension/Provider/GrowthWebProvider.php');
foreach(["'/growth/signals'","'/growth/collectors'","'growth.signals'","'growth.collectors'"] as $needle){
    $assert(str_contains($provider,$needle),'Growth Web provider missing Signal Operations contribution: '.$needle);
}

$controller=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach(['GrowthSignalCollectorBoundary','function signals(','function collectors(','collectorRuns(','collectors()'] as $needle){
    $assert(str_contains($controller,$needle),'Growth Page controller missing Signal Operations contract: '.$needle);
}
$assert(!str_contains($controller,'runCollector('),'Growth Page controller must not execute collector mutations directly.');

$routes=$read('symfony/config/routes.yaml');
foreach(['path: /growth/signals','path: /growth/collectors'] as $route){
    $assert(str_contains($routes,$route),'Growth Signal Operations route missing: '.$route);
}
$assert(substr_count($routes,'App\\Web\\Growth\\GrowthPageController::')===7,'Growth V0.12 must expose exactly seven SSR routes.');

$frontend=$read('frontend/features/growth/workspace.js');
foreach([
    "data-growth-collector-run",
    "/api/v1/growth/collectors/",
    "'X-CSRF-Token'",
    "'X-Idempotency-Key'",
    "runStatus==='partial'",
    "runStatus==='failed'",
] as $needle){
    $assert(str_contains($frontend,$needle),'Growth collector frontend contract missing: '.$needle);
}

foreach(['growth/signals.phtml','growth/collectors.phtml'] as $view){
    $assert(is_file($root.'/app/Interfaces/Web/View/'.$view),'Growth Signal Operations view missing: '.$view);
}

echo "Growth V0.12 Signal Operations architecture: OK\n";
