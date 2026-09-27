<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.3.0','>='),'Growth manifest must remain V0.3+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.3.0','>='),'Growth schema must remain V0.3+.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.3 must remain disabled before delivery cutover.');
$migration='app/migrations/20260921_000068_growth_v030_account_intelligence.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.3 migration contribution is missing.');
foreach(['growth.icp.manage','growth.account.discover','growth.account.enrich','growth.account.score','growth.account.brief'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth intelligence capability missing: '.$capability);
}

$migrationSql=$read($migration);
foreach(['tn_growth_icp_profiles','tn_growth_accounts','tn_growth_account_snapshots','tn_growth_account_icp_matches',"installed_version='0.3.0'"] as $needle){
    $assert(str_contains($migrationSql,$needle),'Growth intelligence migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_icp_profiles','tn_growth_accounts','tn_growth_account_snapshots','tn_growth_account_icp_matches'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth intelligence ownership missing: '.$table);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthIntelligenceService.php');
foreach([
    'GrowthIntelligenceBoundary','GrowthIntelligenceRepositoryInterface','GrowthRepositoryInterface','GrowthMutationReceiptInterface','reviseIcpProfile',
    'IcpMatcher','transactions->transactional','receipts->claim','GrowthEventType::ICP_DRAFTED',
    'GrowthEventType::ICP_REVISED','GrowthEventType::ICP_ACTIVATED','GrowthEventType::ACCOUNT_DISCOVERED',
    'GrowthEventType::ACCOUNT_SNAPSHOT_CAPTURED','GrowthEventType::ACCOUNT_ICP_SCORED',
    'listSignalsBySubject','listCandidatesBySubject','idempotency_key_hash',
] as $needle){
    $assert(str_contains($service,$needle),'Growth intelligence service missing: '.$needle);
}
foreach(['PDO','Symfony\\','Phalcon\\','Infrastructure\\','Platform\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth intelligence application crossed its boundary: '.$forbidden);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthIntelligenceRepository.php');
foreach(['FOR UPDATE','archiveOtherActiveIcpRevisions','viewSnapshot','viewIcpMatch','tn_growth_account_snapshots','tn_growth_account_icp_matches','criteria_json','source_references_json'] as $needle){
    $assert(str_contains($repository,$needle),'Growth intelligence repository missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach(['GrowthIntelligenceRepositoryInterface','MysqlGrowthIntelligenceRepository','GrowthIntelligenceBoundary','GrowthIntelligenceService','IcpMatcher'] as $needle){
    $assert(str_contains($services,$needle),'Growth intelligence DI missing: '.$needle);
}

echo "Growth V0.3 ICP and Account Intelligence architecture: OK\n";
