<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.13.0','Growth V0.13 manifest version must be 0.13.0.');
$assert(($manifest['schema_version']??null)==='0.8.0','Growth V0.13 must keep schema version 0.8.0.');
$assert(in_array('growth.signal.external_webhook',$manifest['contributions']['capabilities']??[],true),'Growth external webhook capability is missing.');

$migration='app/migrations/20260922_000077_growth_v0130_external_signal_webhook.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.13 lifecycle migration is missing.');
$sql=$read($migration);
foreach(["installed_version='0.13.0'","installed_version='0.12.0'","schema_version='0.8.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.13 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.13 migration must remain schema-neutral: '.$forbidden);
}

$contract=$read('app/Domains/Growth/Application/Contract/GrowthApplicationBoundary.php');
$assert(str_contains($contract,'function ingestExternalSignal('),'Growth application boundary external ingress is missing.');

$workflow=$read('app/Domains/Growth/Application/Service/GrowthWorkflowService.php');
foreach([
    'function ingestExternalSignal(','external_signal_',"':external_signal:'",
    "publishAs('SYSTEM'",'growth.external_signal','growth.signal.external_ingest',
    "'ingress'=>'external_webhook'",
] as $needle){
    $assert(str_contains($workflow,$needle),'Growth external ingress runtime missing: '.$needle);
}

$edge=$read('symfony/src/Application/Growth/Integration/GrowthExternalSignalWebhook.php');
foreach([
    'hash_hmac(\'sha256\',$timestamp.\'.\'.$rawBody,$this->secret)',
    'hash_equals(','MAX_BODY_BYTES','maxClockSkew',
    'isEnabled($organizationId,\'growth\')','ingestExternalSignal(',
    'X-TN-Idempotency-Key',
] as $needle){
    $assert(str_contains($edge,$needle),'Growth external webhook edge missing: '.$needle);
}
foreach(['PDO','GrowthRepositoryInterface','GrowthMutationReceiptInterface','tn_growth_'] as $forbidden){
    $assert(!str_contains($edge,$forbidden),'Growth external webhook edge bypasses application boundary: '.$forbidden);
}

$controller=$read('symfony/src/Web/PublicEdge/PublicEdgeController.php');
foreach(['GrowthExternalSignalWebhook','function growthSignalWebhook(','growthSignalWebhook->handle'] as $needle){
    $assert(str_contains($controller,$needle),'Public edge Growth webhook wiring missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
foreach(['cos_web_growth_signal_webhook:','path: /webhooks/growth/signals','PublicEdgeController::growthSignalWebhook'] as $needle){
    $assert(str_contains($routes,$needle),'Growth external webhook route missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    "env(GROWTH_SIGNAL_WEBHOOK_SECRET): ''",
    "env(GROWTH_SIGNAL_WEBHOOK_ACTOR_ID): '0'",
    "env(GROWTH_SIGNAL_WEBHOOK_MAX_CLOCK_SKEW): '300'",
    'App\\Application\\Growth\\Integration\\GrowthExternalSignalWebhook:',
    '$growth: \'@Domains\\Growth\\Application\\Contract\\GrowthApplicationBoundary\'',
    '$modules: \'@Kernel\\Module\\ActiveModuleResolver\'',
] as $needle){
    $assert(str_contains($services,$needle),'Growth external webhook service wiring missing: '.$needle);
}

echo "Growth V0.13 External Signal Webhook architecture: OK\n";
