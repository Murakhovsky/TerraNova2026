<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.35.0','>='),'Growth manifest must remain V0.35+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.31.0','>='),'Growth schema must remain V0.31+.');
$assert(
    in_array('growth.engagement.pre_handoff_linkedin_call_execution',$manifest['contributions']['capabilities']??[],true),
    'Growth LinkedIn/call execution capability is missing.'
);

$migration='app/migrations/20260924_000100_growth_v0350_linkedin_call_execution.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.35 lifecycle migration missing.');
$sql=$read($migration);
foreach(["installed_version='0.35.0'","installed_version='0.34.0'","schema_version='0.31.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.35 migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.35 migration must remain schema-neutral.');
}

$contact=$read('app/Domains/Growth/Domain/GrowthContact.php');
foreach(["'phone'","E.164"] as $needle)$assert(str_contains($contact,$needle),'Growth phone identity support missing: '.$needle);

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
foreach([
    "'growth.send_linkedin'","'growth.place_call'",'proposeGrowthLinkedIn(','proposeGrowthCall(',
    'hasUsableChannelIdentity','post_handoff_call_not_supported','eligible_pre_handoff',
] as $needle){
    $assert(str_contains($service,$needle),'Growth V0.35 execution routing missing: '.$needle);
}
foreach(['PDO','Infrastructure\\','Platform\\Integration'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth application crossed execution boundary: '.$forbidden);
}

$linkedIn=$read('app/Domains/Growth/Automation/Action/GrowthLinkedInHandler.php');
foreach(["public const TYPE='growth.send_linkedin'","identity_type","queueLinkedIn(","ExternalActionIdempotency::resolve"] as $needle){
    $assert(str_contains($linkedIn,$needle),'Growth LinkedIn handler missing: '.$needle);
}
$call=$read('app/Domains/Growth/Automation/Action/GrowthCallHandler.php');
foreach(["public const TYPE='growth.place_call'","E.164","queueCall(","ExternalActionIdempotency::resolve"] as $needle){
    $assert(str_contains($call,$needle),'Growth call handler missing: '.$needle);
}
foreach([$linkedIn,$call] as $handler){
    foreach(['PDO','GuzzleHttp\\','Twilio\\','LinkedIn\\','curl_'] as $forbidden){
        $assert(!str_contains($handler,$forbidden),'Growth action handler bypasses provider-neutral gateway: '.$forbidden);
    }
}

$gateway=$read('app/Domains/Growth/Infrastructure/Integration/N8nGrowthExternalEngagementGateway.php');
foreach([
    'IntegrationOutboxInterface',"'growth.engagement.linkedin'","'growth.engagement.call'",
    "'n8n'","'growth_contact'","queueLinkedIn(","queueCall(",
] as $needle){
    $assert(str_contains($gateway,$needle),'Growth n8n engagement adapter missing: '.$needle);
}
foreach(['curl_','Twilio\\','LinkedIn\\'] as $forbidden){
    $assert(!str_contains($gateway,$forbidden),'Growth n8n adapter must not embed provider SDK/transport: '.$forbidden);
}

$platformPort=$read('app/Platform/Integration/Contract/IntegrationOutboxInterface.php');
$platformAdapter=$read('app/Infrastructure/Platform/Integration/MysqlIntegrationOutbox.php');
$assert(str_contains($platformPort,'enqueue('),'Platform integration outbox port missing enqueue.');
foreach(['IntegrationOutboxInterface','tn_integration_outbox','ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'] as $needle){
    $assert(str_contains($platformAdapter,$needle),'Platform integration outbox adapter missing: '.$needle);
}

$proposal=$read('app/Domains/Growth/Infrastructure/Action/KernelGrowthActionProposalGateway.php');
foreach([
    "'growth.send_linkedin'","'growth.place_call'","executionMode:$activationMode->executionMode()",
    "'growth_contact'","'activation_mode'=>$activationMode->value",
] as $needle){
    $assert(str_contains($proposal,$needle),'Growth Kernel proposal bridge missing governed execution marker: '.$needle);
}
foreach(['execute(','commands->dispatch'] as $forbidden){
    $assert(!str_contains($proposal,$forbidden),'Growth proposal gateway must not execute Actions directly: '.$forbidden);
}

$policy=$read('app/Domains/Growth/Automation/Policy/GrowthPolicyCatalog.php');
foreach(["'growth.send_message'","'growth.send_linkedin'","'growth.place_call'",'PolicyDecision::ApprovalRequired'] as $needle){
    $assert(str_contains($policy,$needle),'Growth V0.35 approval policy missing: '.$needle);
}
$module=$read('app/Domains/Growth/Bootstrap/GrowthDomainModule.php');
foreach(['GrowthSendMessageHandler','GrowthLinkedInHandler','GrowthCallHandler','actionHandlers()'] as $needle){
    $assert(str_contains($module,$needle),'Growth V0.35 module action ownership missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'IntegrationOutboxInterface','MysqlIntegrationOutbox','N8nGrowthExternalEngagementGateway',
    'GrowthExternalEngagementGatewayInterface','GrowthLinkedInHandler','GrowthCallHandler',
] as $needle){
    $assert(str_contains($services,$needle),'Growth V0.35 DI missing: '.$needle);
}

$template=$read('app/Interfaces/Web/View/growth/candidate.phtml');
$assert(str_contains($template,'Approved message / call brief'),'Growth workspace execution copy was not generalized for call execution.');

echo "Growth V0.35 Governed LinkedIn and Call Execution architecture: OK\n";
