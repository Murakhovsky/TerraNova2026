<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.6.0','Growth V0.6 manifest version must be 0.6.0.');
$assert(($manifest['schema_version']??null)==='0.6.0','Growth V0.6 schema version must be 0.6.0.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.6 must remain disabled before delivery cutover.');
$migration='app/migrations/20260922_000071_growth_v060_decision_intelligence.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.6 migration contribution is missing.');
foreach(['growth.qualification.policy','growth.candidate.evaluate','growth.candidate.decision_brief'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth decision capability missing: '.$capability);
}

$migrationSql=$read($migration);
foreach(['tn_growth_qualification_policies','tn_growth_candidate_evaluations','rationale_json','score_json','model_version',"installed_version='0.6.0'"] as $needle){
    $assert(str_contains($migrationSql,$needle),'Growth decision migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_qualification_policies','tn_growth_candidate_evaluations'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth decision ownership missing: '.$table);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthDecisionService.php');
foreach([
    'GrowthDecisionBoundary','GrowthDecisionRepositoryInterface','GrowthRepositoryInterface','GrowthMutationReceiptInterface',
    'QualificationPolicyEvaluator','transactions->transactional','receipts->claim','lockCandidate',
    'GrowthEventType::QUALIFICATION_POLICY_DRAFTED','GrowthEventType::QUALIFICATION_POLICY_REVISED',
    'GrowthEventType::QUALIFICATION_POLICY_ACTIVATED','GrowthEventType::CANDIDATE_EVALUATED',
    'GrowthEventType::CANDIDATE_QUALIFIED','GrowthEventType::CANDIDATE_MONITORING_STARTED',
    'GrowthEventType::CANDIDATE_DISQUALIFIED','idempotency_key_hash',
] as $needle){
    $assert(str_contains($service,$needle),'Growth decision service missing: '.$needle);
}
foreach(['PDO','Symfony\\','Phalcon\\','Infrastructure\\','Platform\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth decision application crossed its boundary: '.$forbidden);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthDecisionRepository.php');
foreach(['FOR UPDATE','archiveOtherActivePolicyRevisions','rationale_json','score_json','failed_criteria_json','model_version','organization_id=:organization_id'] as $needle){
    $assert(str_contains($repository,$needle),'Growth decision repository missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthDecisionRepositoryInterface','MysqlGrowthDecisionRepository',
    'GrowthDecisionBoundary','GrowthDecisionService','QualificationPolicyEvaluator',
] as $needle){
    $assert(str_contains($services,$needle),'Growth decision DI missing: '.$needle);
}

echo "Growth V0.6 Decision Intelligence architecture: OK\n";
