<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.2.0','>='),'Growth manifest must remain V0.2+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.2.0','>='),'Growth schema must remain V0.2+.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.2 must remain disabled by default before delivery cutover.');
$assert(($manifest['contributions']['runtime_module_service']??null)==='growthDomainModule','Growth runtime module contribution is missing.');
$migration='app/migrations/20260921_000067_growth_v020_runtime.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth migration contribution is missing.');

$migrationSql=$read($migration);
foreach([
    'tn_growth_signals','tn_growth_candidates','tn_growth_candidate_signals','tn_growth_operation_receipts',
    "module_id='growth'","installed_version='0.2.0'","schema_version='0.2.0'","status='INSTALLED'",
] as $needle){
    $assert(str_contains($migrationSql,$needle),'Growth migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_signals','tn_growth_candidates','tn_growth_candidate_signals','tn_growth_operation_receipts'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth table ownership missing: '.$table);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthRepository.php');
foreach([
    'organization_id=:organization_id','FOR UPDATE','public function lockCandidate',
    'tn_growth_candidate_signals','rationale_json','score_json','OpportunityCandidate::restore',
] as $needle){
    $assert(str_contains($repository,$needle),'Growth repository hardening missing: '.$needle);
}
$assert(substr_count($repository,'organization_id')>=20,'Growth persistence must remain tenant-scoped.');

$receipt=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthMutationReceipt.php');
foreach(['INSERT IGNORE INTO tn_growth_operation_receipts','payload_fingerprint','hash_equals'] as $needle){
    $assert(str_contains($receipt,$needle),'Growth idempotency receipt missing: '.$needle);
}

$workflow=$read('app/Domains/Growth/Application/Service/GrowthWorkflowService.php');
foreach([
    'GrowthApplicationBoundary','GrowthRepositoryInterface','GrowthMutationReceiptInterface','TransactionManagerInterface',
    'EventBus','AuditRepositoryInterface','receipts->claim','transactions->transactional','lockCandidate(',
    'GrowthEventType::SIGNAL_DETECTED','GrowthEventType::CANDIDATE_DETECTED','GrowthEventType::CANDIDATE_RESEARCHED',
    'GrowthEventType::CANDIDATE_SCORED','GrowthEventType::CANDIDATE_QUALIFIED',
    'GrowthEventType::CANDIDATE_MONITORING_STARTED','GrowthEventType::CANDIDATE_DISQUALIFIED',
    'GrowthEventType::HANDOFF_PREPARED','idempotency_key_hash',
] as $needle){
    $assert(str_contains($workflow,$needle),'Growth workflow missing: '.$needle);
}
foreach(['PDO','Symfony\\','Phalcon\\','Infrastructure\\','Platform\\'] as $forbidden){
    $assert(!str_contains($workflow,$forbidden),'Growth application crossed its boundary: '.$forbidden);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthRepositoryInterface','GrowthMutationReceiptInterface','GrowthApplicationBoundary',
    'GrowthWorkflowService','MysqlGrowthRepository','MysqlGrowthMutationReceipt','GrowthDomainModule',
] as $needle){
    $assert(str_contains($services,$needle),'Growth Symfony DI missing: '.$needle);
}

$module=$read('app/Domains/Growth/Bootstrap/GrowthDomainModule.php');
$assert(str_contains($module,'EventOwningModuleInterface'),'Growth runtime module must own its event vocabulary.');
$assert(str_contains($module,'GrowthEventType::values()'),'Growth runtime module must expose canonical events.');

echo "Growth V0.2 persistence/application runtime architecture: OK\n";
