<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.20.0','>='),'Growth manifest must remain V0.20+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.19.0','>='),'Growth schema must remain V0.19+.');
$assert(in_array('growth.experiments.workspace',$manifest['contributions']['capabilities']??[],true),'Growth Experiment Workspace capability is missing.');

$migration='app/migrations/20260923_000084_growth_v0200_experiment_workspace.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.20 lifecycle migration is missing.');
$sql=$read($migration);
foreach(["installed_version='0.20.0'","installed_version='0.19.0'","schema_version='0.19.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.20 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.20 migration must remain schema-neutral: '.$forbidden);
}

$provider=$read('symfony/src/Web/Experience/Extension/Provider/GrowthWebProvider.php');
foreach([
    "'growth-experiments'","'/growth/experiments'","'growth.experiments'",
    "'Growth Experiments'","'Controlled experiments and attribution'"
] as $needle){
    $assert(str_contains($provider,$needle),'Growth Experiment Workspace provider contribution missing: '.$needle);
}

$controller=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach([
    'GrowthExperimentBoundary','GrowthExperimentDimension','GrowthOutcomeType','GrowthExperimentStatus',
    'function experiments(','function experiment(','experiments($tenant->organizationId()->value()',
    'experimentBrief($tenant->organizationId()->value(),$id)'
] as $needle){
    $assert(str_contains($controller,$needle),'Growth Experiment Workspace controller missing: '.$needle);
}
foreach(['createExperiment(','startExperiment(','pauseExperiment(','resumeExperiment(','completeExperiment(','archiveExperiment(','assignCandidate('] as $forbidden){
    $assert(!str_contains($controller,$forbidden),'Growth SSR controller must remain read-only and use API for experiment mutations: '.$forbidden);
}

$listView=$read('app/Interfaces/Web/View/growth/experiments.phtml');
foreach([
    'data-growth-experiments','Create experiment','data-growth-experiment-create',
    'data-growth-experiment-variant','data-growth-experiment-variant-add',
    'Experiment registry','does not automatically select a winner'
] as $needle){
    $assert(str_contains($listView,$needle),'Growth Experiments list view missing: '.$needle);
}

$detailView=$read('app/Interfaces/Web/View/growth/experiment.phtml');
foreach([
    'data-growth-experiment','data-experiment-id','Variant outcomes',
    'data-growth-experiment-transition','data-growth-experiment-assign',
    'Deterministic weighted assignment','Measurement only — no automatic winner selection',
    'Candidate allocation'
] as $needle){
    $assert(str_contains($detailView,$needle),'Growth Experiment detail view missing: '.$needle);
}

$js=$read('frontend/features/growth/workspace.js');
foreach([
    'initGrowthExperiments','initGrowthExperiment','experimentEndpoint',
    'data-growth-experiment-create','data-growth-experiment-transition',
    'data-growth-experiment-assign','/api/v1/growth/experiments',
    "assignments/'+encodeURIComponent(candidateId)"
] as $needle){
    $assert(str_contains($js,$needle),'Growth Experiment Workspace client runtime missing: '.$needle);
}
foreach(['/winner','selectWinner','executeWinner','sendMessage('] as $forbidden){
    $assert(!str_contains($js,$forbidden),'Growth Experiment Workspace must not select or execute an experiment winner: '.$forbidden);
}

$css=$read('frontend/features/growth/workspace.css');
foreach([
    'tn-growth-experiment-form','tn-growth-experiment-variant','tn-growth-experiment-report',
    'tn-growth-experiment-card','tn-growth-experiment-assign'
] as $needle){
    $assert(str_contains($css,$needle),'Growth Experiment Workspace styles missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
foreach(['path: /growth/experiments','path: /growth/experiments/{id}'] as $path){
    $assert(str_contains($routes,$path),'Growth Experiment SSR route missing: '.$path);
}
$assert(substr_count($routes,'App\\Web\\Growth\\GrowthPageController::')>=10,'Growth SSR surface must include at least ten routes after V0.20.');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])>=54,'Growth canonical API surface must not shrink below V0.19 contract.');

echo "Growth V0.20 Experiment Workspace architecture: OK\n";
