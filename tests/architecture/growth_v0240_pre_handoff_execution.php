<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.24.0','>='),'Growth manifest must remain V0.24+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.22.0','>='),'Growth schema must remain V0.22+.');
$assert(in_array('growth.engagement.pre_handoff_execution',$manifest['contributions']['capabilities']??[],true),'Growth pre-handoff execution capability missing.');
$migration='app/migrations/20260923_000089_growth_v0240_pre_handoff_execution.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.24 lifecycle migration missing.');
$sql=$read($migration);
foreach(["installed_version='0.24.0'","installed_version='0.23.0'","schema_version='0.22.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.24 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.24 lifecycle migration must remain schema-neutral.');
}

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
foreach([
    'count($deals)>1','count($deals)===1','Pre-handoff Growth execution currently supports email only.',
    "'growth_contact'","'growth.send_message'",'proposeGrowthMessage','eligible_pre_handoff','eligible_post_handoff',
    'hasUsableEmailContact','pre_handoff_contact_email_required',
    'ambiguous_sales_deal_binding','pre_handoff_contact_required',
] as $needle){
    $assert(str_contains($service,$needle),'Growth V0.24 execution bridge missing: '.$needle);
}
foreach(['PDO','Domains\\Sales\\','Platform\\Notification\\','Infrastructure\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth V0.24 application crossed boundary: '.$forbidden);
}

$gatewayContract=$read('app/Domains/Growth/Application/Contract/GrowthActionProposalGatewayInterface.php');
$assert(str_contains($gatewayContract,'proposeGrowthMessage('),'Growth action proposal port lacks pre-handoff message proposal.');

$gateway=$read('app/Domains/Growth/Infrastructure/Action/KernelGrowthActionProposalGateway.php');
foreach([
    "type:'growth.send_message'","targetType:'growth_contact'","sourceType:'GROWTH'",
    "executionMode:'APPROVAL_REQUIRED'","riskLevel:'MEDIUM'",'policies->submit',
] as $needle){
    $assert(str_contains($gateway,$needle),'Growth V0.24 Kernel proposal adapter missing: '.$needle);
}
foreach(['execute(','commands->dispatch'] as $forbidden){
    $assert(!str_contains($gateway,$forbidden),'Growth V0.24 proposal gateway must not execute Action directly: '.$forbidden);
}

$handler=$read('app/Domains/Growth/Automation/Action/GrowthSendMessageHandler.php');
foreach([
    'GrowthOutboundMessageGatewayInterface',"public const TYPE='growth.send_message'","targetType!=='growth_contact'",
    '$identityType!==\'email\'','FILTER_VALIDATE_EMAIL','queueEmail(','ExternalActionIdempotency::resolve',
] as $needle){
    $assert(str_contains($handler,$needle),'Growth V0.24 action handler missing: '.$needle);
}
foreach(['Platform\\Notification\\','Infrastructure\\','Domains\\Sales\\','PDO'] as $forbidden){
    $assert(!str_contains($handler,$forbidden),'Growth action handler bypasses owned ports: '.$forbidden);
}

$outbound=$read('app/Domains/Growth/Infrastructure/Notification/PlatformNotificationGrowthOutboundMessageGateway.php');
foreach([
    'GrowthOutboundMessageGatewayInterface','NotificationDispatcherInterface',"Channel::EMAIL",
    "'growth.outbound.message'",'notifications->send',
] as $needle){
    $assert(str_contains($outbound,$needle),'Growth Platform Notification adapter missing: '.$needle);
}

$module=$read('app/Domains/Growth/Bootstrap/GrowthDomainModule.php');
foreach([
    'ActionOwningModuleInterface','PolicyProvidingModuleInterface','BootstrapPolicyProvidingModuleInterface',
    'GrowthSendMessageHandler::TYPE','actionHandlers()','bootstrapPolicies()',
] as $needle){
    $assert(str_contains($module,$needle),'Growth V0.24 action ownership missing: '.$needle);
}

$policy=$read('app/Domains/Growth/Automation/Policy/GrowthPolicyCatalog.php');
foreach(["'growth.send_message'",'PolicyDecision::ApprovalRequired'] as $needle){
    $assert(str_contains($policy,$needle),'Growth V0.24 approval policy missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'GrowthOutboundMessageGatewayInterface','PlatformNotificationGrowthOutboundMessageGateway',
    'GrowthSendMessageHandler','NotificationDispatcherInterface',
] as $needle){
    $assert(str_contains($services,$needle),'Growth V0.24 DI missing: '.$needle);
}

echo "Growth V0.24 Governed Pre-Handoff Engagement Execution architecture: OK\n";
