<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.15.0','Growth V0.15 manifest version must be 0.15.0.');
$assert(($manifest['schema_version']??null)==='0.15.0','Growth V0.15 schema version must be 0.15.0.');
foreach(['growth.learning.feedback','growth.learning.brief'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth learning capability missing: '.$capability);
}

$migration='app/migrations/20260923_000079_growth_v0150_learning_feedback.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.15 migration is missing.');
$sql=$read($migration);
foreach([
    'tn_growth_learning_bindings','tn_growth_outcomes','uq_growth_learning_binding_external',
    'uq_growth_outcome_source_event','economic_value',"installed_version='0.15.0'","schema_version='0.15.0'"
] as $needle){
    $assert(str_contains($sql,$needle),'Growth learning migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_learning_bindings','tn_growth_outcomes'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth learning table ownership missing: '.$table);
}

$handoffContract=$read('app/Domains/Growth/Application/Contract/GrowthHandoffRepositoryInterface.php');
$handoffRepo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthHandoffRepository.php');
foreach(['candidateByTargetReference','target_reference_type','target_reference_id'] as $needle){
    $assert(str_contains($handoffContract.$handoffRepo,$needle),'Growth handoff feedback bridge missing: '.$needle);
}

$consumer=$read('app/Domains/Growth/Automation/Event/GrowthOutcomeFeedbackConsumer.php');
foreach([
    'DurableEventConsumerInterface','growth.outcome-feedback.v1','candidateByTargetReference',
    'bindExternalSubject','LeadChanged::TYPE','SalesEventType::DEAL_WON','SalesEventType::DEAL_LOST',
    'GrowthOutcomeType::Qualified','GrowthOutcomeType::ReplyReceived','GrowthOutcomeType::MeetingCompleted',
    'GrowthEventType::OUTCOME_RECORDED','GrowthEventType::LEARNING_BINDING_CREATED',
    'isEnabled($event->organizationId,\'growth\')',
] as $needle){
    $assert(str_contains($consumer,$needle),'Growth feedback consumer missing: '.$needle);
}
foreach([
    'Domains\\Sales\\Infrastructure','SalesWriteService','DealRepositoryInterface','PDO',
    'message_body','identity_value','full_name',
] as $forbidden){
    $assert(!str_contains($consumer,$forbidden),'Growth feedback consumer crossed Sales/persistence/privacy boundary: '.$forbidden);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthLearningRepository.php');
foreach([
    'INSERT IGNORE INTO tn_growth_learning_bindings','INSERT IGNORE INTO tn_growth_outcomes',
    'candidateByExternalSubject','outcomeSummary','won_value_by_currency','organization_id=:organization_id',
] as $needle){
    $assert(str_contains($repository,$needle),'Growth learning repository missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthLearningRepositoryInterface','MysqlGrowthLearningRepository',
    'GrowthLearningBoundary','GrowthLearningService',
    'GrowthOutcomeFeedbackConsumer',
    "growth.outcome-feedback.v1: '@Domains\\Growth\\Automation\\Event\\GrowthOutcomeFeedbackConsumer'",
] as $needle){
    $assert(str_contains($services,$needle),'Growth learning DI/durable consumer wiring missing: '.$needle);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach(['GrowthLearningBoundary','function learningBrief('] as $needle){
    $assert(str_contains($controller,$needle),'Growth learning API missing: '.$needle);
}
$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])===39,'Growth V0.15 must expose exactly 39 canonical Growth API routes.');
$assert(str_contains($routes,'path: /api/v1/growth/candidates/{id}/learning'),'Growth learning brief route missing.');

echo "Growth V0.15 Learning Feedback architecture: OK\n";
