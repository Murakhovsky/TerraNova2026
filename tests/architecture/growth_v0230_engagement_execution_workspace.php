<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.23.0','>='),'Growth manifest must remain V0.23+.');
$assert(($manifest['schema_version']??null)==='0.22.0','Growth V0.23 must keep schema version 0.22.0.');
$assert(in_array('growth.engagement.execution_workspace',$manifest['contributions']['capabilities']??[],true),'Growth execution workspace capability missing.');

$migration='app/migrations/20260923_000087_growth_v0230_engagement_execution_workspace.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.23 lifecycle migration missing.');
$sql=$read($migration);
$assert(str_contains($sql,"installed_version='0.23.0'")&&str_contains($sql,"installed_version='0.22.0'"),'Growth V0.23 lifecycle transition missing.');
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden)$assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.23 must remain schema-neutral.');

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
foreach(['executionEligibility','eligible_post_handoff','already_proposed','channel_not_supported','target_reference_id'] as $needle){
    $assert(str_contains($service,$needle),'Growth V0.23 execution eligibility missing: '.$needle);
}

$controller=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach(['GrowthEngagementBoundary','GrowthEngagementExecutionBoundary',"'engagement'=>",'engagement_execution'] as $needle){
    $assert(str_contains($controller,$needle),'Growth V0.23 candidate workspace wiring missing: '.$needle);
}

$template=$read('app/Interfaces/Web/View/growth/candidate.phtml');
foreach(['data-growth-engagement','data-growth-engagement-generate','data-growth-engagement-decision','data-growth-engagement-execution','Kernel Action bridge'] as $needle){
    $assert(str_contains($template,$needle),'Growth V0.23 candidate template missing: '.$needle);
}

$js=$read('frontend/features/growth/workspace.js');
foreach(['runEngagementGenerate','runEngagementDecision','runEngagementExecution',"/engagement/recommendations","/execution"] as $needle){
    $assert(str_contains($js,$needle),'Growth V0.23 workspace JS missing: '.$needle);
}
foreach(['/api/v1/sales/actions/','/api/v1/sales/approvals/'] as $forbidden){
    $assert(!str_contains($js,$forbidden),'Growth workspace must not execute or approve Sales actions directly.');
}

echo "Growth V0.23 Engagement Execution Workspace architecture: OK\n";
