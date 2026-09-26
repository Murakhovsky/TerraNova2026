<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.28.0','>='),'Growth manifest must remain V0.28+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.28.0','>='),'Growth schema must remain V0.28+.');
foreach(['growth.signal.collector.credentialed_json','growth.signal.json_source.manage'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Growth credentialed JSON capability missing: '.$capability);
}

$migration='app/migrations/20260924_000093_growth_v0280_credentialed_json_collector.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.28 migration missing.');
$sql=$read($migration);
foreach([
    'tn_growth_json_signal_sources','credential_reference','url_hash','auth_mode','api_key_header',
    "installed_version='0.28.0'","installed_version='0.27.0'","schema_version='0.28.0'"
] as $needle){
    $assert(str_contains($sql,$needle),'Growth credentialed JSON migration missing: '.$needle);
}
foreach(['token VARCHAR','api_key VARCHAR','secret VARCHAR'] as $forbidden){
    $assert(!str_contains(strtolower($sql),strtolower($forbidden)),'Growth JSON source table must not persist raw secret material: '.$forbidden);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_json_signal_sources'"),'Growth JSON signal source table ownership missing.');

$vault=$read('app/Infrastructure/Platform/Integration/EnvironmentCredentialVault.php');
foreach(['CredentialVaultInterface','env:\\/\\/','getenv(','JSON_THROW_ON_ERROR','Credential secret material is unavailable'] as $needle){
    $assert(str_contains($vault,$needle),'Environment CredentialVault invariant missing: '.$needle);
}
foreach(['echo ','print_r(','var_dump(','error_log('] as $forbidden){
    $assert(!str_contains($vault,$forbidden),'Environment CredentialVault must not log secret material: '.$forbidden);
}

$source=$read('app/Domains/Growth/Domain/GrowthJsonSignalSource.php');
foreach([
    "AUTH_BEARER='bearer'","AUTH_API_KEY_HEADER='api_key_header'",
    "strtolower((string)(\$parts['scheme']??''))!=='https'","(int)\$parts['port']!==443",
    "'/^X-[A-Za-z0-9-]{1,63}$/'"
] as $needle){
    $assert(str_contains($source,$needle),'Growth JsonSignalSource invariant missing: '.$needle);
}

$reader=$read('app/Domains/Growth/Infrastructure/Feed/SafeHttpCredentialedJsonSignalReader.php');
foreach([
    'CredentialVaultInterface','ExternalCallExecutor','ExternalCallPolicy','FILTER_FLAG_NO_PRIV_RANGE','FILTER_FLAG_NO_RES_RANGE',
    'CURLOPT_RESOLVE','CURLOPT_FOLLOWLOCATION=>false','CURLOPT_MAXREDIRS=>0','MAX_BODY_BYTES=2_097_152',
    "'Authorization: Bearer '","'api_key'","'Accept: application/json'"
] as $needle){
    $assert(str_contains($reader,$needle),'Growth credentialed JSON safe transport missing: '.$needle);
}
foreach(['CURLOPT_SSL_VERIFYPEER=>false','CURLOPT_SSL_VERIFYHOST=>0','PDO','credential_reference'] as $forbidden){
    $assert(!str_contains($reader,$forbidden),'Growth credentialed JSON reader bypasses security boundary: '.$forbidden);
}

$parser=$read('app/Domains/Growth/Infrastructure/Feed/CredentialedJsonSignalParser.php');
foreach(['JSON_THROW_ON_ERROR',"'items'",'ExternalJsonSignalEntry','count($items)>500'] as $needle){
    $assert(str_contains($parser,$needle),'Growth credentialed JSON parser missing: '.$needle);
}

$collector=$read('app/Domains/Growth/Infrastructure/Collector/CredentialedJsonSignalCollector.php');
foreach([
    'SignalCollectorInterface','GrowthJsonSignalSourceRepositoryInterface','GrowthJsonSignalReaderInterface',
    "return 'credentialed_json'",'listEnabled(','new CollectedSignal('
] as $needle){
    $assert(str_contains($collector,$needle),'Growth credentialed JSON collector missing: '.$needle);
}
foreach(['PDO','curl_','CredentialVaultInterface'] as $forbidden){
    $assert(!str_contains($collector,$forbidden),'Growth credentialed JSON collector bypasses its ports: '.$forbidden);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthJsonSignalSourceService.php');
foreach([
    'GrowthJsonSignalSourceBoundary','GrowthJsonSignalSourceRepositoryInterface','GrowthMutationReceiptInterface',
    'create_json_signal_source','enable_json_signal_source','disable_json_signal_source',
    'SIGNAL_JSON_SOURCE_CREATED','SIGNAL_JSON_SOURCE_ENABLED','SIGNAL_JSON_SOURCE_DISABLED',
    "unset(\$row['credential_reference'])",'credential_reference_hash'
] as $needle){
    $assert(str_contains($service,$needle),'Growth JSON source service missing: '.$needle);
}
foreach(['PDO','curl_','getenv(','Infrastructure\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth JSON source application crossed boundary: '.$forbidden);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach([
    'GrowthJsonSignalSourceBoundary','jsonSignalSources','createJsonSignalSource',
    'enableJsonSignalSource','disableJsonSignalSource'
] as $needle){
    $assert(str_contains($controller,$needle),'Growth JSON source API missing: '.$needle);
}
$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])===68,'Growth V0.28 must expose exactly 68 canonical Growth API routes.');
foreach([
    '/api/v1/growth/json-signal-sources',
    '/api/v1/growth/json-signal-sources/{id}/enable',
    '/api/v1/growth/json-signal-sources/{id}/disable',
] as $path){
    $assert(str_contains($routes,'path: '.$path),'Growth JSON source route missing: '.$path);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'EnvironmentCredentialVault','CredentialVaultInterface',
    'GrowthJsonSignalSourceRepositoryInterface','MysqlGrowthJsonSignalSourceRepository',
    'GrowthJsonSignalSourceBoundary','GrowthJsonSignalSourceService',
    'GrowthJsonSignalReaderInterface','SafeHttpCredentialedJsonSignalReader',
    'CredentialedJsonSignalParser','CredentialedJsonSignalCollector'
] as $needle){
    $assert(str_contains($services,$needle),'Growth credentialed JSON DI missing: '.$needle);
}

echo "Growth V0.28 Credentialed JSON Signal Collector architecture: OK\n";
