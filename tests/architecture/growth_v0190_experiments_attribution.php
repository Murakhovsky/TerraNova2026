<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.19.0','Growth V0.19 manifest version must be 0.19.0.');
$assert(($manifest['schema_version']??null)==='0.19.0','Growth V0.19 schema version must be 0.19.0.');
foreach(['growth.experiments','growth.attribution'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth experiment capability missing: '.$capability);
}

$migration='app/migrations/20260923_000083_growth_v0190_experiments_attribution.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.19 migration is missing.');
$sql=$read($migration);
foreach([
    'tn_growth_experiments','tn_growth_experiment_assignments','variants_json','context_snapshot_json',
    'uq_growth_experiment_candidate',"installed_version='0.19.0'","schema_version='0.19.0'"
] as $needle){
    $assert(str_contains($sql,$needle),'Growth experiment migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_experiments','tn_growth_experiment_assignments'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth experiment table ownership missing: '.$table);
}

$experiment=$read('app/Domains/Growth/Domain/GrowthExperiment.php');
foreach(['chooseVariant','allocationWeight','Only running Growth experiment can pause','Only running or paused Growth experiment can complete'] as $needle){
    $assert(str_contains($experiment,$needle),'Growth Experiment aggregate invariant missing: '.$needle);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthExperimentRepository.php');
foreach([
    'candidateHasTerminalOutcome','COUNT(DISTINCT a.candidate_id)','o.observed_at>=a.assigned_at',
    'o.observed_at<=:ended_at','attribution_rule','candidate_outcomes_after_assignment_until_experiment_end',
    "o2.observed_at>=a.assigned_at",'won_value_by_currency'
] as $needle){
    $assert(str_contains($repository,$needle),'Growth attribution repository missing: '.$needle);
}
foreach(['Domains\\Sales\\','tn_leads','tn_client_cases'] as $forbidden){
    $assert(!str_contains($repository,$forbidden),'Growth attribution must remain Growth-owned: '.$forbidden);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthExperimentService.php');
foreach([
    'GrowthExperimentBoundary','GrowthExperimentRepositoryInterface','GrowthMutationReceiptInterface',
    'create_growth_experiment','assign_growth_experiment_candidate','chooseVariant',
    'candidateHasTerminalOutcome','isTerminal','GrowthExperimentAssignmentSource::Deterministic',
    'EXPERIMENT_CANDIDATE_ASSIGNED','attributionReport'
] as $needle){
    $assert(str_contains($service,$needle),'Growth Experiment service missing: '.$needle);
}
foreach(['PDO','Domains\\Sales\\','sendMessage','ActionProposal'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth Experiment application crossed boundary or executes outreach: '.$forbidden);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach([
    'GrowthExperimentBoundary','createExperiment','experiments(','experiment(','startExperiment','pauseExperiment',
    'resumeExperiment','completeExperiment','archiveExperiment','assignExperimentCandidate','experimentReport'
] as $needle){
    $assert(str_contains($controller,$needle),'Growth Experiment API missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])===54,'Growth V0.19 must expose exactly 54 canonical Growth API routes.');
foreach([
    '/api/v1/growth/experiments',
    '/api/v1/growth/experiments/{id}/start',
    '/api/v1/growth/experiments/{id}/pause',
    '/api/v1/growth/experiments/{id}/resume',
    '/api/v1/growth/experiments/{id}/complete',
    '/api/v1/growth/experiments/{id}/archive',
    '/api/v1/growth/experiments/{id}/assignments/{candidateId}',
    '/api/v1/growth/experiments/{id}/report',
] as $path){
    $assert(str_contains($routes,'path: '.$path),'Growth Experiment route missing: '.$path);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthExperimentRepositoryInterface','MysqlGrowthExperimentRepository',
    'GrowthExperimentBoundary','GrowthExperimentService',
] as $needle){
    $assert(str_contains($services,$needle),'Growth Experiment DI missing: '.$needle);
}

echo "Growth V0.19 Experiments & Attribution architecture: OK\n";
