<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.36.0','Growth V0.36 manifest version must be 0.36.0.');
$assert(($manifest['schema_version']??null)==='0.36.0','Growth V0.36 schema version must be 0.36.0.');
$assert(in_array('growth.engagement.delivery_feedback',$manifest['contributions']['capabilities']??[],true),'Growth delivery feedback capability missing.');

$migration='app/migrations/20260924_000101_growth_v0360_engagement_delivery_feedback.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.36 migration missing.');
$sql=$read($migration);
foreach([
    'tn_growth_engagement_delivery_observations',
    'uq_growth_engagement_delivery_source_event',
    "installed_version='0.36.0'",
    "installed_version='0.35.0'",
    "schema_version='0.36.0'",
    "schema_version='0.31.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.36 migration missing: '.$needle);
}
foreach(['body','recipient','identity_value'] as $forbidden){
    $assert(!str_contains(strtolower($sql),$forbidden),'Growth delivery observations must not persist outbound content/identity: '.$forbidden);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_engagement_delivery_observations'"),'Growth delivery table ownership missing.');

$executionContract=$read('app/Domains/Growth/Application/Contract/GrowthEngagementExecutionRepositoryInterface.php');
$assert(str_contains($executionContract,'byActionId('),'Growth execution repository must resolve by Kernel action id.');

$deliveryContract=$read('app/Domains/Growth/Application/Contract/GrowthEngagementDeliveryRepositoryInterface.php');
foreach(['recordOrVerify(','latestForExecution(','forExecution('] as $needle){
    $assert(str_contains($deliveryContract,$needle),'Growth delivery repository contract missing: '.$needle);
}

$status=$read('app/Domains/Growth/Domain/EngagementDeliveryStatus.php');
foreach(["case Delivered='delivered'","case Completed='completed'","case NoAnswer='no_answer'",'supportsChannel(','isTerminal('] as $needle){
    $assert(str_contains($status,$needle),'Growth delivery status model missing: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementDeliveryService.php');
foreach([
    'GrowthEngagementDeliveryBoundary','byActionId(','recordOrVerify(',
    "'growth.send_linkedin'","'growth.place_call'",'ENGAGEMENT_DELIVERY_OBSERVED',
    "'SYSTEM'","'growth-engagement-webhook'",
] as $needle){
    $assert(str_contains($service,$needle),'Growth delivery service missing: '.$needle);
}
foreach(['PDO','Infrastructure\\','curl_','GuzzleHttp\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth delivery application crossed boundary: '.$forbidden);
}

$executionService=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
foreach(['GrowthEngagementDeliveryRepositoryInterface',"'delivery_observations'","'latest_delivery'",'forExecution('] as $needle){
    $assert(str_contains($executionService,$needle),'Growth execution brief missing delivery feedback: '.$needle);
}

$webhook=$read('symfony/src/Application/Growth/Integration/GrowthEngagementDeliveryWebhook.php');
foreach([
    'GrowthEngagementDeliveryBoundary','X-TN-Idempotency-Key','hash_hmac',
    'X-TN-Signature','kernel_action_id','organization_id','recordExternalStatus(',
] as $needle){
    $assert(str_contains($webhook,$needle),'Growth delivery webhook missing: '.$needle);
}

$publicEdge=$read('symfony/src/Web/PublicEdge/PublicEdgeController.php');
foreach(['GrowthEngagementDeliveryWebhook','growthEngagementDeliveryWebhook'] as $needle){
    $assert(str_contains($publicEdge,$needle),'Public edge delivery callback missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
$assert(str_contains($routes,'/webhooks/growth/engagement/delivery'),'Growth delivery callback route missing.');

$services=$read('symfony/config/services.yaml');
foreach([
    'GROWTH_ENGAGEMENT_WEBHOOK_SECRET','GrowthEngagementDeliveryRepositoryInterface',
    'MysqlGrowthEngagementDeliveryRepository','GrowthEngagementDeliveryBoundary',
    'GrowthEngagementDeliveryService','GrowthEngagementDeliveryWebhook',
] as $needle){
    $assert(str_contains($services,$needle),'Growth delivery DI/config missing: '.$needle);
}

$template=$read('app/Interfaces/Web/View/growth/candidate.phtml');
foreach(['latest_delivery','Delivery status'] as $needle){
    $assert(str_contains($template,$needle),'Growth candidate workspace missing delivery feedback: '.$needle);
}

echo "Growth V0.36 Engagement Delivery Feedback architecture: OK\n";
