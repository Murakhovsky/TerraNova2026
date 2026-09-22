<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.4.0','>='),'Growth manifest must remain V0.4+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.4.0','>='),'Growth schema must remain V0.4+.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.4 must remain disabled before delivery cutover.');
$migration='app/migrations/20260922_000069_growth_v040_buying_committee.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.4 migration contribution is missing.');
foreach(['growth.contact.discover','growth.contact.enrich','growth.buying_committee.assess','growth.buying_committee.brief'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth buying committee capability missing: '.$capability);
}

$migrationSql=$read($migration);
foreach(['tn_growth_contacts','tn_growth_account_contacts','tn_growth_contact_snapshots','tn_growth_buying_committee_assessments',"installed_version='0.4.0'"] as $needle){
    $assert(str_contains($migrationSql,$needle),'Growth buying committee migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_contacts','tn_growth_account_contacts','tn_growth_contact_snapshots','tn_growth_buying_committee_assessments'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Growth buying committee ownership missing: '.$table);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthBuyingCommitteeService.php');
foreach([
    'GrowthBuyingCommitteeBoundary','GrowthBuyingCommitteeRepositoryInterface','GrowthIntelligenceRepositoryInterface',
    'GrowthMutationReceiptInterface','BuyingCommitteeAnalyzer','transactions->transactional','receipts->claim',
    'GrowthEventType::CONTACT_DISCOVERED','GrowthEventType::CONTACT_SNAPSHOT_CAPTURED',
    'GrowthEventType::BUYING_COMMITTEE_ASSESSED','viewContactSnapshot','viewAssessment','idempotency_key_hash',
] as $needle){
    $assert(str_contains($service,$needle),'Growth buying committee service missing: '.$needle);
}
foreach(['PDO','Symfony\\','Phalcon\\','Infrastructure\\','Platform\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth buying committee application crossed its boundary: '.$forbidden);
}

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthBuyingCommitteeRepository.php');
foreach([
    'INSERT IGNORE INTO tn_growth_account_contacts','isContactLinked','latestContactSnapshotsForAccount','source_references_json','identity_hash',
    'relationship_strength','model_version','viewAssessment','organization_id=:organization_id',
] as $needle){
    $assert(str_contains($repository,$needle),'Growth buying committee repository missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthBuyingCommitteeRepositoryInterface','MysqlGrowthBuyingCommitteeRepository',
    'GrowthBuyingCommitteeBoundary','GrowthBuyingCommitteeService','BuyingCommitteeAnalyzer',
] as $needle){
    $assert(str_contains($services,$needle),'Growth buying committee DI missing: '.$needle);
}

echo "Growth V0.4 Buying Committee Intelligence architecture: OK\n";
