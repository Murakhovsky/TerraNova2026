<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.21.0','>='),'Growth manifest must remain V0.21+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.21.0','>='),'Growth schema must remain V0.21+.');
$assert(in_array('growth.experiments.decision',$manifest['contributions']['capabilities']??[],true),'Growth experiment decision capability is missing.');

$migration='app/migrations/20260923_000085_growth_v0210_experiment_decision_intelligence.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.21 migration is missing.');
$sql=$read($migration);
foreach([
    'tn_growth_experiment_decision_runs','tn_growth_experiment_decision_recommendations',
    'context_snapshot_json','promoted_variant_key','evidence_ids_json',
    "installed_version='0.21.0'","schema_version='0.21.0'"
] as $needle){
    $assert(str_contains($sql,$needle),'Growth experiment decision migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_experiment_decision_runs','tn_growth_experiment_decision_recommendations'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth experiment decision table ownership missing: '.$table);
}

$prompt=$read('app/Domains/Growth/Application/AI/GrowthExperimentDecisionPrompt.php');
foreach([
    'growth-experiment-decision-v1','allowed_evidence_ids','allowed_variant_keys',
    'Never claim statistical significance','Do not infer causality','Do not activate, execute, archive or mutate'
] as $needle){
    $assert(str_contains($prompt,$needle),'Growth experiment decision prompt invariant missing: '.$needle);
}

$gateway=$read('app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthExperimentDecisionGateway.php');
foreach(['StructuredLlmClientInterface','StructuredLlmRequest',"useCase:'growth.experiment.decision'",'ExperimentDecisionType::tryFrom'] as $needle){
    $assert(str_contains($gateway,$needle),'Growth experiment decision gateway missing: '.$needle);
}
foreach(['OpenAI','Anthropic','api_key','token='] as $forbidden){
    $assert(!str_contains($gateway,$forbidden),'Growth experiment decision gateway must remain provider-neutral: '.$forbidden);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthExperimentDecisionService.php');
foreach([
    'GrowthExperimentDecisionBoundary','GrowthExperimentRepositoryInterface',
    'GrowthExperimentDecisionRepositoryInterface','GrowthExperimentDecisionGatewayInterface',
    'generate_experiment_decision','attributionReport','promotion_sample_floor_met',
    'MIN_TOTAL_ASSIGNED_FOR_PROMOTION','MIN_PER_VARIANT_ASSIGNED_FOR_PROMOTION',
    'supersedeProposedForExperiment','validateDraft',
    'EXPERIMENT_DECISION_RECOMMENDATION_CREATED',
    'EXPERIMENT_DECISION_RECOMMENDATION_ACCEPTED',
    'EXPERIMENT_DECISION_RECOMMENDATION_DISMISSED',
    'EXPERIMENT_DECISION_RECOMMENDATION_SUPERSEDED',
] as $needle){
    $assert(str_contains($service,$needle),'Growth experiment decision service missing: '.$needle);
}
foreach([
    'PDO','Domains\\Sales\\','ActionProposal','ActionPolicyService',
    'lockExperiment(','updateExperiment(','startExperiment(','completeExperiment('
] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth experiment decision runtime crossed authority boundary: '.$forbidden);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthExperimentDecisionRepository.php');
foreach([
    'FOR UPDATE','context_snapshot_json','supersedeProposedForExperiment',
    'provider','model','input_tokens','output_tokens','cost_amount',
    'organization_id=:organization_id'
] as $needle){
    $assert(str_contains($repository,$needle),'Growth experiment decision repository missing: '.$needle);
}

$events=$read('app/Domains/Growth/Automation/Event/GrowthEventType.php');
foreach([
    'growth.experiment_decision.run_started','growth.experiment_decision.run_completed',
    'growth.experiment_decision.run_failed','growth.experiment_decision.recommendation_created',
    'growth.experiment_decision.recommendation_accepted',
    'growth.experiment_decision.recommendation_dismissed',
    'growth.experiment_decision.recommendation_superseded'
] as $event){
    $assert(str_contains($events,$event),'Growth experiment decision event missing: '.$event);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach([
    'GrowthExperimentDecisionBoundary','generateExperimentDecision',
    'acceptExperimentDecision','dismissExperimentDecision','experimentDecisionBrief'
] as $needle){
    $assert(str_contains($controller,$needle),'Growth experiment decision API missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])>=58,'Growth V0.21 canonical API surface must not shrink below 58 routes.');
foreach([
    '/api/v1/growth/experiments/{id}/decision/recommendations',
    '/api/v1/growth/experiments/{id}/decision/recommendations/{recommendationId}/accept',
    '/api/v1/growth/experiments/{id}/decision/recommendations/{recommendationId}/dismiss',
    '/api/v1/growth/experiments/{id}/decision',
] as $path){
    $assert(str_contains($routes,'path: '.$path),'Growth experiment decision route missing: '.$path);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthExperimentDecisionRepositoryInterface','MysqlGrowthExperimentDecisionRepository',
    'GrowthExperimentDecisionGatewayInterface','StructuredLlmGrowthExperimentDecisionGateway',
    'GrowthExperimentDecisionBoundary','GrowthExperimentDecisionService'
] as $needle){
    $assert(str_contains($services,$needle),'Growth experiment decision DI missing: '.$needle);
}

echo "Growth V0.21 Experiment Decision Intelligence architecture: OK\n";
