<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.18.0','>='),'Growth manifest must remain V0.18+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.17.0','>='),'Growth schema must remain V0.17+.');
$assert(in_array('growth.learning.optimization_workspace',$manifest['contributions']['capabilities']??[],true),'Growth Optimization Workspace capability is missing.');

$migration='app/migrations/20260923_000082_growth_v0180_optimization_workspace.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.18 lifecycle migration is missing.');
$sql=$read($migration);
foreach(["installed_version='0.18.0'","installed_version='0.17.0'","schema_version='0.17.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.18 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.18 migration must remain schema-neutral: '.$forbidden);
}

$controller=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach(['GrowthOptimizationBoundary',"'optimization'=>\$this->optimization->optimizationBrief"] as $needle){
    $assert(str_contains($controller,$needle),'Growth Optimization Workspace controller missing: '.$needle);
}

$view=$read('app/Interfaces/Web/View/growth/learning.phtml');
foreach([
    'data-growth-learning','Learning → governed draft','terminal Candidate outcomes',
    'Current active criteria','Proposed draft criteria','Risks','Assumptions',
    'data-growth-optimization-generate','data-growth-optimization-decision="accept"',
    'data-growth-optimization-decision="dismiss"','data-growth-optimization-materialize',
    'Activation remains a separate human-controlled operation',
] as $needle){
    $assert(str_contains($view,$needle),'Growth Optimization Workspace view missing: '.$needle);
}
$assert(!str_contains($view,'/activate'),'Growth Optimization Workspace must not expose activation endpoint.');

$js=$read('frontend/features/growth/workspace.js');
foreach([
    'initGrowthLearning','optimizationEndpoint','data-growth-optimization-generate',
    'data-growth-optimization-decision','data-growth-optimization-materialize',
    'Creating draft revision','Recommendation became stale',
] as $needle){
    $assert(str_contains($js,$needle),'Growth Optimization Workspace client runtime missing: '.$needle);
}
$assert(!str_contains($js,"optimization/recommendations/'+encodeURIComponent(recommendationId)+'/activate"),'Growth Optimization client must not activate policies.');

$css=$read('frontend/features/growth/workspace.css');
foreach(['tn-growth-optimization-grid','tn-growth-optimization-card','tn-growth-optimization-actions','tn-growth-optimization-evidence'] as $needle){
    $assert(str_contains($css,$needle),'Growth Optimization Workspace styles missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
$assert(str_contains($routes,'path: /growth/learning'),'Growth Learning SSR route is missing.');
$assert(substr_count($routes,'App\\Web\\Growth\\GrowthPageController::')>=8,'Growth V0.18 SSR route surface must not shrink below eight routes.');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])>=44,'Growth canonical API surface must not shrink below V0.18 contract.');

echo "Growth V0.18 Optimization Workspace architecture: OK\n";
