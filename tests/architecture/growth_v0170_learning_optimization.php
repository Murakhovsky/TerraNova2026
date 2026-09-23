<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.17.0','Growth V0.17 manifest version must be 0.17.0.');
$assert(($manifest['schema_version']??null)==='0.17.0','Growth V0.17 schema version must be 0.17.0.');
$assert(in_array('growth.learning.optimize',$manifest['contributions']['capabilities']??[],true),'Growth learning optimization capability is missing.');

$migration='app/migrations/20260923_000081_growth_v0170_learning_optimization.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.17 migration is missing.');
$sql=$read($migration);
foreach([
    'tn_growth_optimization_runs','tn_growth_optimization_recommendations','context_snapshot_json',
    'proposed_criteria_json','materialized_revision',"installed_version='0.17.0'","schema_version='0.17.0'"
] as $needle){
    $assert(str_contains($sql,$needle),'Growth optimization migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_optimization_runs','tn_growth_optimization_recommendations'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth optimization table ownership missing: '.$table);
}

foreach([
    'app/Domains/Growth/Domain/IcpProfile.php',
    'app/Domains/Growth/Domain/QualificationPolicy.php',
] as $domainFile){
    $source=$read($domainFile);
    $assert(str_contains($source,'Only active'),'Growth optimization requires active-only revision invariant: '.$domainFile);
}

$prompt=$read('app/Domains/Growth/Application/AI/GrowthOptimizationPrompt.php');
foreach([
    'growth-learning-optimization-v1','allowed_evidence_ids','complete replacement criteria object',
    'Do not infer causality from correlation','Never activate, execute or mutate'
] as $needle){
    $assert(str_contains($prompt,$needle),'Growth optimization prompt invariant missing: '.$needle);
}

$gateway=$read('app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthOptimizationGateway.php');
foreach(['StructuredLlmClientInterface','StructuredLlmRequest',"useCase:'growth.learning.optimize'",'LearningOptimizationDraft'] as $needle){
    $assert(str_contains($gateway,$needle),'Growth optimization gateway missing: '.$needle);
}
foreach(['OpenAI','Anthropic','api_key','token='] as $forbidden){
    $assert(!str_contains($gateway,$forbidden),'Growth optimization gateway must remain provider-neutral: '.$forbidden);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthOptimizationRepository.php');
foreach([
    'optimizationContext','terminalSamples','signalPerformance($organizationId,$sampleLimit)','learning:terminal_outcomes','learning:score:',
    'learning:icp_fit','metricId','activeTargets','context_snapshot_json','FOR UPDATE'
] as $needle){
    $assert(str_contains($repository,$needle),'Growth optimization repository missing: '.$needle);
}
foreach(['Domains\\Sales\\','tn_leads','tn_client_cases'] as $forbidden){
    $assert(!str_contains($repository,$forbidden),'Growth optimization repository crossed target-domain boundary: '.$forbidden);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthOptimizationService.php');
foreach([
    'GrowthOptimizationBoundary','GrowthOptimizationRepositoryInterface','GrowthOptimizationGatewayInterface',
    'GrowthIntelligenceBoundary','GrowthDecisionBoundary','minimum_terminal_sample',
    'generate_learning_optimization','validateDraft','outside deterministic learning context',
    'reviseIcpProfile','reviseQualificationPolicy','growth-opt-materialize-','finalizeMaterialized',
    'Recovered existing matching draft revision',
    'OPTIMIZATION_RECOMMENDATION_MATERIALIZED','OPTIMIZATION_RECOMMENDATION_STALE'
] as $needle){
    $assert(str_contains($service,$needle),'Growth optimization service missing: '.$needle);
}
foreach(['PDO','Domains\\Sales\\','Infrastructure\\Persistence'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth optimization application crossed boundary: '.$forbidden);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach([
    'GrowthOptimizationBoundary','generateOptimization','optimizationBrief',
    'acceptOptimization','dismissOptimization','materializeOptimization'
] as $needle){
    $assert(str_contains($controller,$needle),'Growth optimization API missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])===44,'Growth V0.17 must expose exactly 44 canonical Growth API routes.');
foreach([
    '/api/v1/growth/learning/optimization',
    '/api/v1/growth/learning/optimization/recommendations',
    '/api/v1/growth/learning/optimization/recommendations/{recommendationId}/accept',
    '/api/v1/growth/learning/optimization/recommendations/{recommendationId}/dismiss',
    '/api/v1/growth/learning/optimization/recommendations/{recommendationId}/materialize',
] as $path){
    $assert(str_contains($routes,'path: '.$path),'Growth optimization route missing: '.$path);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthOptimizationRepositoryInterface','MysqlGrowthOptimizationRepository',
    'GrowthOptimizationGatewayInterface','StructuredLlmGrowthOptimizationGateway',
    'GrowthOptimizationBoundary','GrowthOptimizationService',
] as $needle){
    $assert(str_contains($services,$needle),'Growth optimization DI missing: '.$needle);
}

echo "Growth V0.17 Learning Optimization architecture: OK\n";
