<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.50.0','>='),'Growth manifest must remain V0.50+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.50.0','>='),'Growth schema must remain V0.50+.');
foreach(['growth.market.resumable_discovery','growth.market.partial_retry'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'V0.50 capability missing: '.$capability);
}
foreach([
    'app/migrations/20260926_000113_growth_v0480_market_discovery.sql',
    'app/migrations/20260926_000114_growth_v0490_cos_for_cos_vertical_slice.sql',
    'app/migrations/20260926_000115_growth_v0500_release_hardening.sql',
] as $migration){
    $assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth migration chain missing: '.$migration);
}

$migration=$read('app/migrations/20260926_000115_growth_v0500_release_hardening.sql');
foreach(['lease_token','lease_expires_at','attempt_count','ix_growth_market_run_lease',"installed_version='0.50.0'"] as $needle){
    $assert(str_contains($migration,$needle),'V0.50 migration hardening missing: '.$needle);
}

$repoContract=$read('app/Domains/Growth/Application/Contract/GrowthMarketDiscoveryRepositoryInterface.php');
$repo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthMarketDiscoveryRepository.php');
foreach(['acquireRunLease','lease_expires_at<NOW(6)',"status='running'",'lease_token=:lease_token'] as $needle){
    $assert(str_contains($repoContract.$repo,$needle),'Market discovery lease contract missing: '.$needle);
}
$assert(str_contains($repo,'organization_id=:organization_id'),'Market lease/runtime persistence must remain tenant-scoped.');

$service=$read('app/Domains/Growth/Application/Service/GrowthMarketDiscoveryService.php');
foreach([
    'acquireRunLease(','in_progress','rejectedCount','errorSummaries','observedCount()',
    'runtimeCursor','cursor_advanced','resumed',
] as $needle){
    $assert(str_contains($service,$needle),'Market discovery recoverability invariant missing: '.$needle);
}
$claimPos=strpos($service,"'run_market_discovery'");
$leasePos=strpos($service,'acquireRunLease(',$claimPos===false?0:$claimPos);
$sourcePos=strpos($service,'->discover(',$leasePos===false?0:$leasePos);
$assert($claimPos!==false&&$leasePos!==false&&$sourcePos!==false&&$claimPos<$leasePos&&$leasePos<$sourcePos,'Idempotency and run lease must be admitted before provider I/O.');
$assert(str_contains($service,"status==='completed'"),'Completed/partial cursor policy missing.');

$batch=$read('app/Domains/Growth/Application/DTO/GrowthMarketDiscoveryBatch.php');
foreach(['rejectedCount','errorSummaries','observedCount'] as $needle)$assert(str_contains($batch,$needle),'Batch observability missing: '.$needle);

$source=$read('app/Domains/Growth/Infrastructure/Market/CredentialedJsonGrowthMarketSource.php');
foreach([
    'FILTER_FLAG_NO_PRIV_RANGE','CURLOPT_FOLLOWLOCATION=>false','CredentialVaultInterface','ExternalCallExecutor',
    'provider exceeded the requested item limit','$rejected++',
] as $needle)$assert(str_contains($source,$needle),'Market source security/rejection accounting missing: '.$needle);

$api=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
$assert(str_contains($api,'$tenant->organizationId()->value()'),'Market API must obtain organization scope from TenantContext.');
$assert(!str_contains($api,"input['organization_id']"),'Market API must not accept tenant identity from request payload.');

$assert(str_contains($service,"unset(\$row['credential_reference'])"),'Credential reference must be redacted from Market workspace/API projection.');
$assert(str_contains($service,'credential_reference_hash'),'Redacted Market projection must retain only a safe credential-reference fingerprint.');

$workflow=$read('.github/workflows/process.yml');
foreach(['growth_v0480_market_discovery.php','growth_v0490_cos_for_cos_vertical_slice.php','growth_v0500_release_hardening.php'] as $needle){
    $assert(str_contains($workflow,$needle),'Growth regression gate missing from CI: '.$needle);
}

$docs=strtolower($read('docs/architecture/growth-v0500-release-hardening.md'));
foreach([
    'tenant isolation','security','idempotency','retries','observability','regression','documentation truth',
    'partial run does not advance the universe cursor','expired run lease',
] as $needle){
    $assert(str_contains($docs,$needle),'V0.50 release hardening documentation missing: '.$needle);
}

echo "Growth V0.50 release hardening architecture: OK\n";
