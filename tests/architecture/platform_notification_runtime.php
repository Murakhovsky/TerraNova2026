<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$migration=$read('app/migrations/20260923_000088_platform_notification_runtime.sql');
foreach([
    'cos_notification_deliveries','organization_id','delivery_id','notification_id','provider_message_id',
    'uq_cos_notification_delivery',
] as $needle){
    $assert(str_contains($migration,$needle),'Platform Notification runtime migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'cos_notification_deliveries'"),'Platform Notification delivery table ownership missing.');

$template=$read('app/Infrastructure/Platform/Notification/BuiltinNotificationTemplateRepository.php');
foreach(['TemplateRepositoryInterface',"GROWTH_OUTBOUND_MESSAGE='growth.outbound.message'","Channel::EMAIL"] as $needle){
    $assert(str_contains($template,$needle),'Platform Notification built-in template missing: '.$needle);
}

$renderer=$read('app/Infrastructure/Platform/Notification/SimpleNotificationTemplateRenderer.php');
$assert(str_contains($renderer,'TemplateRendererInterface'),'Platform Notification renderer contract missing.');

$channel=$read('app/Infrastructure/Platform/Notification/N8nEmailNotificationChannel.php');
foreach([
    'NotificationChannelInterface','Channel::EMAIL','tn_integration_outbox',"notification.email",
    'INSERT IGNORE','dedupe_key','fingerprint(','Platform Notification idempotency conflict',
] as $needle){
    $assert(str_contains($channel,$needle),'Platform Notification n8n email channel missing: '.$needle);
}
foreach(['Domains\\Growth\\','Domains\\Sales\\'] as $forbidden){
    $assert(!str_contains($channel,$forbidden),'Platform Notification channel must stay domain-neutral: '.$forbidden);
}

$deliveries=$read('app/Infrastructure/Platform/Notification/MysqlNotificationDeliveryRepository.php');
foreach(['DeliveryRepositoryInterface','cos_notification_deliveries','organization_id','ON DUPLICATE KEY UPDATE'] as $needle){
    $assert(str_contains($deliveries,$needle),'Platform Notification delivery repository missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'BuiltinNotificationTemplateRepository','SimpleNotificationTemplateRenderer','MysqlNotificationDeliveryRepository',
    'N8nEmailNotificationChannel','Platform\\Notification\\Service\\NotificationDispatcher',
    'Platform\\Notification\\Contract\\NotificationDispatcherInterface',
] as $needle){
    $assert(str_contains($services,$needle),'Platform Notification runtime DI missing: '.$needle);
}

echo "Platform Notification durable email runtime architecture: OK\n";
