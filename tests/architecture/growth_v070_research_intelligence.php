<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.7.0','>='),'Growth manifest must remain V0.7+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.7.0','>='),'Growth schema must remain V0.7+.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.7 must remain disabled before delivery cutover.');
$migration='app/migrations/20260922_000072_growth_v070_research_intelligence.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.7 migration contribution is missing.');
foreach(['growth.research.generate','growth.research.accept','growth.research.brief'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth research capability missing: '.$capability);
}

$migrationSql=$read($migration);
foreach(['tn_growth_research_runs','tn_growth_research_proposals','prompt_version','schema_version','context_snapshot_json','accepted_at',"installed_version='0.7.0'"] as $needle){
    $assert(str_contains($migrationSql,$needle),'Growth research migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_research_runs','tn_growth_research_proposals'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth research ownership missing: '.$table);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthResearchService.php');
foreach([
    'GrowthResearchBoundary','GrowthResearchGatewayInterface','GrowthResearchRepositoryInterface',
    'GrowthRepositoryInterface','GrowthIntelligenceRepositoryInterface','GrowthBuyingCommitteeRepositoryInterface',
    'GrowthMutationReceiptInterface','transactions->transactional','gateway->propose','validateEvidence',
    'markResearched','GrowthEventType::RESEARCH_RUN_STARTED','GrowthEventType::RESEARCH_RUN_COMPLETED','GrowthEventType::RESEARCH_RUN_FAILED',
    'GrowthEventType::RESEARCH_PROPOSAL_CREATED','GrowthEventType::RESEARCH_PROPOSAL_ACCEPTED',
    'GrowthEventType::CANDIDATE_RESEARCHED','idempotency_key_hash',
] as $needle){
    $assert(str_contains($service,$needle),'Growth research service missing: '.$needle);
}
foreach(['PDO','Symfony\\','Phalcon\\','Infrastructure\\','Platform\\','identity_value','identity_type'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth research application crossed its boundary or leaked contact identity: '.$forbidden);
}
$gatewayPos=strpos($service,'gateway->propose');
$firstTransactionPos=strpos($service,'transactions->transactional');
$assert($gatewayPos!==false&&$firstTransactionPos!==false&&$gatewayPos>$firstTransactionPos,'Growth LLM call should happen after run transaction setup.');

$proposalDomain=$read('app/Domains/Growth/Domain/ResearchProposal.php');
foreach(['Application\\','Infrastructure\\','Symfony\\','Phalcon\\','PDO'] as $forbidden){
    $assert(!str_contains($proposalDomain,$forbidden),'Growth ResearchProposal Domain dependency violation: '.$forbidden);
}

$gateway=$read('app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthResearchGateway.php');
foreach(['StructuredLlmClientInterface','StructuredLlmRequest','growth.research.propose','GrowthResearchPrompt::schema()'] as $needle){
    $assert(str_contains($gateway,$needle),'Growth structured LLM gateway missing: '.$needle);
}
foreach(['OpenAI','Anthropic','api_key','token='] as $forbidden){
    $assert(!str_contains($gateway,$forbidden),'Growth research gateway must remain provider neutral: '.$forbidden);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthResearchRepository.php');
foreach([
    'FOR UPDATE',
    '(organization_id,run_id,candidate_id,status,prompt_version,schema_version,context_snapshot_json',
    ':context_snapshot_json',
    'accepted_at','provider','model','input_tokens','output_tokens','cost_amount','organization_id=:organization_id'
] as $needle){
    $assert(str_contains($repository,$needle),'Growth research repository missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthResearchRepositoryInterface','MysqlGrowthResearchRepository',
    'GrowthResearchGatewayInterface','StructuredLlmGrowthResearchGateway',
    'GrowthResearchBoundary','GrowthResearchService',
] as $needle){
    $assert(str_contains($services,$needle),'Growth research DI missing: '.$needle);
}

echo "Growth V0.7 Research Intelligence architecture: OK\n";
