<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.16.0','Growth V0.16 manifest version must be 0.16.0.');
$assert(($manifest['schema_version']??null)==='0.15.0','Growth V0.16 must keep schema version 0.15.0.');
$assert(in_array('growth.learning.workspace',$manifest['contributions']['capabilities']??[],true),'Growth learning workspace capability is missing.');

$migration='app/migrations/20260923_000080_growth_v0160_learning_workspace.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.16 lifecycle migration is missing.');
$sql=$read($migration);
foreach(["installed_version='0.16.0'","installed_version='0.15.0'","schema_version='0.15.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.16 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.16 migration must remain schema-neutral: '.$forbidden);
}

$contract=$read('app/Domains/Growth/Application/Contract/GrowthWorkspaceReadModelInterface.php');
foreach(['function learningOverview(','function outcomes('] as $needle){
    $assert(str_contains($contract,$needle),'Growth learning workspace read contract missing: '.$needle);
}

$readModel=$read('app/Domains/Growth/Infrastructure/ReadModel/MySql/MysqlGrowthWorkspaceReadModel.php');
foreach([
    'tn_growth_outcomes','COUNT(DISTINCT candidate_id)','won_value_by_currency',
    'top_reasons','economic_value','reason_code','account_name',
] as $needle){
    $assert(str_contains($readModel,$needle),'Growth learning read model missing: '.$needle);
}
foreach(['INSERT INTO','UPDATE tn_growth','DELETE FROM tn_growth'] as $forbidden){
    $assert(!str_contains($readModel,$forbidden),'Growth learning workspace read model must remain read-only: '.$forbidden);
}

$provider=$read('symfony/src/Web/Experience/Extension/Provider/GrowthWebProvider.php');
foreach(["'growth-learning'","'/growth/learning'","'growth.learning'"] as $needle){
    $assert(str_contains($provider,$needle),'Growth Learning provider contribution missing: '.$needle);
}

$controller=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach([
    'GrowthLearningBoundary','function learning(','learningOverview(','outcomes(',
    "'learning'=>\$this->learning->learningBrief",
] as $needle){
    $assert(str_contains($controller,$needle),'Growth Learning page controller missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
$assert(str_contains($routes,'path: /growth/learning'),'Growth Learning SSR route missing.');
$assert(substr_count($routes,'App\\Web\\Growth\\GrowthPageController::')===8,'Growth V0.16 must expose exactly eight Growth SSR routes.');

$learningView='app/Interfaces/Web/View/growth/learning.phtml';
$candidateView='app/Interfaces/Web/View/growth/candidate.phtml';
$assert(is_file($root.'/'.$learningView),'Growth Learning view is missing.');
$assert(str_contains($read($learningView),'Outcomes, not vanity metrics'),'Growth Learning view contract missing.');
foreach(['Observed outcomes','Open Growth Learning','reply_received','meeting_completed'] as $needle){
    $assert(str_contains($read($candidateView),$needle),'Growth Candidate learning panel missing: '.$needle);
}

echo "Growth V0.16 Learning Workspace architecture: OK\n";
