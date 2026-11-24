<?php
declare(strict_types=1);
namespace Infrastructure\Platform\Notification;
use Platform\Notification\Contract\NotificationHandoffReadPort;
use PDO;
final readonly class MysqlNotificationHandoffReadModel implements NotificationHandoffReadPort
{
    public function __construct(private PDO $connection){}
    public function status(string $organizationId,string $notificationId):?array
    {
        $q=$this->connection->prepare('SELECT id,status,attempts,sent_at FROM tn_integration_outbox WHERE integration="n8n" AND event_type="notification.email" AND dedupe_key=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,"$.organization_id"))=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,"$.notification_id"))=? LIMIT 1');
        $q->execute(['notification:'.$notificationId,$organizationId,$notificationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        return ['notification_id'=>$notificationId,'outbox_id'=>(int)$row['id'],'state'=>match($row['status']){
            'pending'=>'queued','processing'=>'processing','sent'=>'handed_off','failed'=>'failed',default=>'unknown',
        },'attempts'=>(int)$row['attempts'],'handoff_at'=>$row['sent_at'],'recipient_delivery_verified'=>false];
    }
}
