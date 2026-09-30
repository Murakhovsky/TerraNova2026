<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$contract=$read('app/Domains/Growth/Application/Contract/GrowthWorkspaceReadModelInterface.php');
$assert(str_contains($contract,'productionEvidence('),'Growth production evidence read contract is missing.');

$readModel=$read('app/Domains/Growth/Infrastructure/ReadModel/MySql/MysqlGrowthWorkspaceReadModel.php');
foreach([
    'tn_growth_market_memberships','tn_growth_candidate_signals','tn_growth_buying_committee_assessments',
    'tn_growth_engagement_recommendations','tn_growth_engagement_execution_links','tn_growth_engagement_responses',
    'tn_growth_conversation_routes','tn_growth_handoff_attempts','tn_growth_outcomes',
    "route='sales'","target_domain='sales'","source_domain='sales'",
] as $needle){
    $assert(str_contains($readModel,$needle),'Growth production evidence query missing: '.$needle);
}

$service=$read('symfony/src/Application/Growth/Operations/GrowthProductionSmokeService.php');
foreach(['ModuleReadinessDiagnostic','GrowthWorkspaceReadModelInterface','productionEvidence(','sales_outcome_feedback','sales_handoff_or_route','sales_golden_path'] as $needle){
    $assert(str_contains($service,$needle),'Growth production smoke service missing: '.$needle);
}
foreach(['PDO','ModuleControlService','detectCandidate(','dispatch('] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth production smoke service must not mutate runtime: '.$forbidden);
}

$command=$read('symfony/src/Command/GrowthProductionCutoverCommand.php');
foreach([
    "name:'cos:growth:cutover'","'status'","'enable'","'verify'","'rollback'",
    'ENABLE_GROWTH','DISABLE_GROWTH','automatic_rollback','schema_rollback',
    'ModuleControlService','ModuleLifecycleRepositoryInterface','install(','enable(','GrowthProductionSmokeService',
] as $needle){
    $assert(str_contains($command,$needle),'Growth production cutover command missing: '.$needle);
}

echo "Growth production cutover architecture: OK\n";
