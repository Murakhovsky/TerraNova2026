<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.8.0','>='),'Growth manifest must remain V0.8+.');
$assert(($manifest['schema_version']??null)==='0.8.0','Growth V0.8 schema version must be 0.8.0.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.8 must remain disabled before delivery cutover.');
$migration='app/migrations/20260922_000073_growth_v080_handoff_protocol.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.8 migration contribution is missing.');
foreach(['growth.handoff.dispatch','growth.handoff.brief','growth.handoff.targets'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth handoff capability missing: '.$capability);
}

$migrationSql=$read($migration);
foreach(['tn_growth_handoff_attempts','package_json','payload_fingerprint','target_reference_id',"installed_version='0.8.0'"] as $needle){
    $assert(str_contains($migrationSql,$needle),'Growth handoff migration missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_handoff_attempts'"),'Growth handoff ownership missing.');

$status=$read('app/Domains/Growth/Domain/OpportunityCandidateStatus.php');
$assert(str_contains($status,"case HandoffPending = 'handoff_pending'"),'Growth handoff_pending lifecycle state is missing.');
$candidate=$read('app/Domains/Growth/Domain/OpportunityCandidate.php');
foreach(['startHandoffDispatch','markHandoffDispatchFailed','markHandedOff','markRejectedByTargetDomain'] as $needle){
    $assert(str_contains($candidate,$needle),'Growth candidate handoff transition missing: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthHandoffService.php');
foreach([
    'GrowthHandoffBoundary','GrowthHandoffRepositoryInterface','GrowthHandoffTargetRegistry','GrowthMutationReceiptInterface',
    'transactions->transactional','receipts->claim','OpportunityHandoff::fromArray','hasRunningAttempt',
    'startHandoffDispatch','markHandoffDispatchFailed','markHandedOff','markRejectedByTargetDomain',
    'growth-handoff-','GrowthEventType::HANDOFF_DISPATCH_STARTED','GrowthEventType::HANDOFF_DISPATCH_FAILED',
    'GrowthEventType::HANDOFF_ACCEPTED','GrowthEventType::HANDOFF_REJECTED','idempotency_key_hash',
] as $needle){
    $assert(str_contains($service,$needle),'Growth handoff service missing: '.$needle);
}
foreach(['PDO','Symfony\\','Phalcon\\','Infrastructure\\','Platform\\','Domains\\Sales\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth handoff application crossed its boundary: '.$forbidden);
}
$targetCall=strpos($service,'target->accept');
$setupTransaction=strpos($service,'transactions->transactional');
$assert($targetCall!==false&&$setupTransaction!==false&&$targetCall>$setupTransaction,'Growth target call must happen outside setup transaction.');

$repository=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthHandoffRepository.php');
foreach(['package_json',"status=\\'running\\'",'hasRunningAttempt','target_reference_type','target_reference_id','organization_id=:organization_id'] as $needle){
    $assert(str_contains($repository,$needle),'Growth handoff repository missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthHandoffTargetInterface','growth.handoff_target','GrowthHandoffTargetRegistry',
    'GrowthHandoffRepositoryInterface','MysqlGrowthHandoffRepository',
    'GrowthHandoffBoundary','GrowthHandoffService',
] as $needle){
    $assert(str_contains($services,$needle),'Growth handoff DI missing: '.$needle);
}

echo "Growth V0.8 Cross-domain Handoff Protocol architecture: OK\n";
