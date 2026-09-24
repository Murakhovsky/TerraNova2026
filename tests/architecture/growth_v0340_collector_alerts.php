<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(($manifest['version']??null)==='0.34.0','Growth V0.34 manifest version must be 0.34.0.');
$assert(($manifest['schema_version']??null)==='0.31.0','Growth V0.34 schema version must be 0.31.0.');
$assert(in_array('growth.signal.polling_alerts',$manifest['contributions']['capabilities']??[],true),'Growth polling alerts capability is missing.');

$migration='app/migrations/20260924_000099_growth_v0340_collector_alert_subscriptions.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.34 migration contribution is missing.');
$sql=$read($migration);
foreach([
    'tn_growth_collector_alert_subscriptions','recipient_email','uq_growth_collector_alert_email',
    "installed_version='0.34.0'","schema_version='0.31.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.34 migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_collector_alert_subscriptions'"),'Growth collector alert table ownership is missing.');

$service=$read('app/Domains/Growth/Application/Service/GrowthCollectorAlertService.php');
foreach([
    'GrowthCollectorAlertBoundary','GrowthCollectorAlertSubscriptionRepositoryInterface','GrowthMutationReceiptInterface',
    'createSubscription(','setEnabled(','COLLECTOR_ALERT_SUBSCRIPTION_CREATED','idempotency_key_hash',
] as $needle){
    $assert(str_contains($service,$needle),'Growth collector alert service missing: '.$needle);
}
foreach(['PDO','tn_growth_collector_alert_subscriptions','Platform\\Notification'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth collector alert application crossed boundary: '.$forbidden);
}

$gateway=$read('app/Domains/Growth/Infrastructure/Notification/PlatformNotificationGrowthCollectorIncidentAlertGateway.php');
foreach([
    'NotificationDispatcherInterface','growth.collector.incident','Channel::EMAIL','growth_incident_id',
    "'opened','resolved'",
] as $needle){
    $assert(str_contains($gateway,$needle),'Growth collector incident alert gateway missing: '.$needle);
}
$assert(!str_contains($gateway,'tn_users'),'Growth collector alert gateway must not infer Identity recipients.');

$incident=$read('app/Domains/Growth/Application/Service/GrowthSignalPollingIncidentService.php');
foreach([
    'GrowthCollectorAlertSubscriptionRepositoryInterface','GrowthCollectorIncidentAlertGatewayInterface',
    'afterCommit(','listEnabled(','queueAlertsAfterCommit(','$this->alerts->queue(',
] as $needle){
    $assert(str_contains($incident,$needle),'Growth incident alert integration missing: '.$needle);
}

$templateRepo=$read('app/Infrastructure/Platform/Notification/BuiltinNotificationTemplateRepository.php');
foreach(['GROWTH_COLLECTOR_INCIDENT','growth.collector.incident','builtin-growth-collector-incident-email-v1'] as $needle){
    $assert(str_contains($templateRepo,$needle),'Growth collector incident notification template missing: '.$needle);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach([
    'GrowthCollectorAlertBoundary','collectorAlertSubscriptions(','createCollectorAlertSubscription(',
    'enableCollectorAlertSubscription(','disableCollectorAlertSubscription(',
] as $needle){
    $assert(str_contains($controller,$needle),'Growth collector alert API missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
foreach([
    '/api/v1/growth/collector-alert-subscriptions',
    '/api/v1/growth/collector-alert-subscriptions/{id}/enable',
    '/api/v1/growth/collector-alert-subscriptions/{id}/disable',
] as $route){
    $assert(str_contains($routes,'path: '.$route),'Growth collector alert route missing: '.$route);
}

$page=$read('symfony/src/Web/Growth/GrowthPageController.php');
$assert(str_contains($page,'alert_subscriptions'),'Growth collector workspace subscription projection is missing.');

$view=$read('app/Interfaces/Web/View/growth/collectors.phtml');
foreach([
    'data-growth-collector-alert-subscriptions','Collector incident email alerts','data-growth-collector-alert-create',
    'data-growth-collector-alert-toggle',
] as $needle){
    $assert(str_contains($view,$needle),'Growth collector alert workspace missing: '.$needle);
}

$frontend=$read('frontend/features/growth/workspace.js');
foreach([
    'data-growth-collector-alert-create','data-growth-collector-alert-toggle',
    '/api/v1/growth/collector-alert-subscriptions',
] as $needle){
    $assert(str_contains($frontend,$needle),'Growth collector alert browser integration missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'MysqlGrowthCollectorAlertSubscriptionRepository','GrowthCollectorAlertSubscriptionRepositoryInterface',
    'GrowthCollectorAlertService','GrowthCollectorAlertBoundary',
    'PlatformNotificationGrowthCollectorIncidentAlertGateway','GrowthCollectorIncidentAlertGatewayInterface',
] as $needle){
    $assert(str_contains($services,$needle),'Growth collector alert DI missing: '.$needle);
}

echo "Growth V0.34 Collector Incident Email Alerts architecture: OK\n";
