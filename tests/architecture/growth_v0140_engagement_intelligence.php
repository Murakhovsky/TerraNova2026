<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.14.0','>='),'Growth manifest must remain V0.14+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.14.0','>='),'Growth schema must remain V0.14+.');
$assert(in_array('growth.engagement.intelligence',$manifest['contributions']['capabilities']??[],true),'Growth engagement capability is missing.');
$migration='app/migrations/20260922_000078_growth_v0140_engagement_intelligence.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.14 migration is missing.');

$sql=$read($migration);
foreach([
    'tn_growth_engagement_runs','tn_growth_engagement_recommendations','context_snapshot_json',
    'message_angle','evidence_ids_json','decision_reason',"installed_version='0.14.0'","schema_version='0.14.0'"
] as $needle){
    $assert(str_contains($sql,$needle),'Growth engagement migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_engagement_runs','tn_growth_engagement_recommendations'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth engagement table ownership missing: '.$table);
}

$prompt=$read('app/Domains/Growth/Application/AI/GrowthEngagementPrompt.php');
foreach(['growth-engagement-v1','allowed_evidence_ids','allowed_contact_ids','available_channels','Do not write outreach copy','Do not execute'] as $needle){
    $assert(str_contains($prompt,$needle),'Growth engagement prompt invariant missing: '.$needle);
}

$gateway=$read('app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthEngagementGateway.php');
foreach(['StructuredLlmClientInterface','StructuredLlmRequest',"useCase:'growth.engagement.recommend'",'allowsChannel'] as $needle){
    $assert(str_contains($gateway,$needle),'Growth engagement gateway missing: '.$needle);
}
foreach(['OpenAI','Anthropic','api_key','token='] as $forbidden){
    $assert(!str_contains($gateway,$forbidden),'Growth engagement gateway must remain provider-neutral: '.$forbidden);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementService.php');
foreach([
    'GrowthEngagementBoundary','GrowthEngagementGatewayInterface','GrowthEngagementRepositoryInterface',
    'GrowthRepositoryInterface','GrowthIntelligenceRepositoryInterface','GrowthBuyingCommitteeRepositoryInterface',
    'generate_engagement_recommendation','lockCandidate','supersedeProposedForCandidate','validateDraft',
    'ENGAGEMENT_RECOMMENDATION_CREATED','ENGAGEMENT_RECOMMENDATION_ACCEPTED',
    'ENGAGEMENT_RECOMMENDATION_DISMISSED','ENGAGEMENT_RECOMMENDATION_SUPERSEDED',
    'allowed_evidence_ids','allowed_contact_ids',
] as $needle){
    $assert(str_contains($service,$needle),'Growth engagement service missing: '.$needle);
}
foreach(['PDO','Symfony\\','Phalcon\\','identity_value'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth engagement application crossed boundary or leaked contact identity value: '.$forbidden);
}
$assert(str_contains($service,'identity_type'),'Growth engagement must derive available channels from contact identity type without exposing the identity value.');

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthEngagementRepository.php');
foreach([
    'FOR UPDATE',"status=\\'proposed\\'",'context_snapshot_json','supersedeProposedForCandidate',
    'provider','model','input_tokens','output_tokens','cost_amount','organization_id=:organization_id'
] as $needle){
    $assert(str_contains($repository,$needle),'Growth engagement repository missing: '.$needle);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach(['GrowthEngagementBoundary','generateEngagement','acceptEngagement','dismissEngagement','engagementBrief'] as $needle){
    $assert(str_contains($controller,$needle),'Growth engagement API missing: '.$needle);
}
$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])>=38,'Growth V0.14 canonical API surface must not shrink below 38 routes.');
foreach([
    '/api/v1/growth/candidates/{id}/engagement/recommendations',
    '/api/v1/growth/candidates/{id}/engagement/recommendations/{recommendationId}/accept',
    '/api/v1/growth/candidates/{id}/engagement/recommendations/{recommendationId}/dismiss',
    '/api/v1/growth/candidates/{id}/engagement',
] as $path){
    $assert(str_contains($routes,'path: '.$path),'Growth engagement route missing: '.$path);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthEngagementRepositoryInterface','MysqlGrowthEngagementRepository',
    'GrowthEngagementGatewayInterface','StructuredLlmGrowthEngagementGateway',
    'GrowthEngagementBoundary','GrowthEngagementService',
] as $needle){
    $assert(str_contains($services,$needle),'Growth engagement DI missing: '.$needle);
}

echo "Growth V0.14 Engagement Intelligence architecture: OK\n";
