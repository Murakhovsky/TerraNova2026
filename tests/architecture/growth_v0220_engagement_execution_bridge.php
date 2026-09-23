<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.22.0','Growth V0.22 manifest version must be 0.22.0.');
$assert(($manifest['schema_version']??null)==='0.22.0','Growth V0.22 schema version must be 0.22.0.');
$assert(in_array('growth.engagement.execution',$manifest['contributions']['capabilities']??[],true),'Growth engagement execution capability is missing.');

$migration='app/migrations/20260923_000086_growth_v0220_engagement_execution_bridge.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.22 migration is missing.');
$sql=$read($migration);
foreach([
    'tn_growth_engagement_execution_links',
    'uq_growth_engagement_execution_recommendation',
    'payload_fingerprint',
    "installed_version='0.22.0'",
    "schema_version='0.22.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'Growth engagement execution migration missing: '.$needle);
}
$assert(!str_contains($sql,'body '),'Growth execution persistence must not duplicate outbound body.');

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_engagement_execution_links'"),'Growth engagement execution table ownership missing.');

$learning=$read('app/Domains/Growth/Application/Contract/GrowthLearningRepositoryInterface.php');
$assert(str_contains($learning,'externalSubjectsForCandidate('),'Growth learning binding lookup by Candidate is missing.');

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
foreach([
    'GrowthEngagementExecutionBoundary','GrowthEngagementExecutionRepositoryInterface',
    'GrowthEngagementRepositoryInterface','GrowthLearningRepositoryInterface',
    'GrowthMutationReceiptInterface','GrowthActionProposalGatewayInterface',
    "EngagementRecommendationStatus::Accepted",
    "externalSubjectsForCandidate($organizationId,$candidateId,'sales','sales_deal')",
    'count($deals)!==1',
    "'engagement_execution_payload'",
    "'sales.send_message'",
    'ENGAGEMENT_EXECUTION_PROPOSED',
] as $needle){
    $assert(str_contains($service,$needle),'Growth engagement execution service missing: '.$needle);
}
foreach(['PDO','Domains\\Sales\\','ActionProposal','ActionPolicyService','ActionService','ExecuteSalesActionCommand'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth engagement application crossed execution boundary: '.$forbidden);
}

$gateway=$read('app/Domains/Growth/Infrastructure/Action/KernelGrowthActionProposalGateway.php');
foreach([
    'GrowthActionProposalGatewayInterface','ActionPolicyService','ActionService',
    "type:'sales.send_message'","targetType:'deal'","sourceType:'GROWTH'",
    "executionMode:'APPROVAL_REQUIRED'","riskLevel:'MEDIUM'",
    'policies->submit',
] as $needle){
    $assert(str_contains($gateway,$needle),'Growth Kernel action proposal adapter missing: '.$needle);
}
foreach(['execute(','ExecuteSalesActionCommand','commands->dispatch'] as $forbidden){
    $assert(!str_contains($gateway,$forbidden),'Growth execution bridge must not directly execute/dispatch Kernel Action: '.$forbidden);
}

$salesPolicies=$read('app/Domains/Sales/Automation/Policy/SalesPolicyCatalog.php');
$assert(
    str_contains($salesPolicies,"'sales.send_message', [], PolicyDecision::ApprovalRequired"),
    'Default Sales send_message policy must require approval.'
);

$repo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthEngagementExecutionRepository.php');
foreach([
    'INSERT IGNORE INTO tn_growth_engagement_execution_links',
    'payload_fingerprint','byRecommendation','createOrVerify',
    'organization_id=:organization_id',
] as $needle){
    $assert(str_contains($repo,$needle),'Growth engagement execution repository missing: '.$needle);
}
$assert(!str_contains($repo,'body'),'Growth engagement execution repository must not persist outbound body.');

$events=$read('app/Domains/Growth/Automation/Event/GrowthEventType.php');
$assert(str_contains($events,'growth.engagement.execution_proposed'),'Growth engagement execution event is missing.');

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach(['GrowthEngagementExecutionBoundary','proposeEngagementExecution','engagementExecution'] as $needle){
    $assert(str_contains($controller,$needle),'Growth engagement execution API missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])===60,'Growth V0.22 must expose exactly 60 canonical Growth API routes.');
$executionPath='/api/v1/growth/candidates/{id}/engagement/recommendations/{recommendationId}/execution';
$assert(substr_count($routes,'path: '.$executionPath)===2,'Growth engagement execution must expose GET + POST on one canonical path.');

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthEngagementExecutionRepositoryInterface','MysqlGrowthEngagementExecutionRepository',
    'GrowthActionProposalGatewayInterface','KernelGrowthActionProposalGateway',
    'GrowthEngagementExecutionBoundary','GrowthEngagementExecutionService',
] as $needle){
    $assert(str_contains($services,$needle),'Growth engagement execution DI missing: '.$needle);
}

echo "Growth V0.22 Governed Engagement Execution Bridge architecture: OK\n";
