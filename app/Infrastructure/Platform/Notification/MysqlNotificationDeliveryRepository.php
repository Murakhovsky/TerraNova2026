<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Notification;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use InvalidArgumentException;
use Platform\Notification\Contract\DeliveryRepositoryInterface;
use Platform\Notification\Model\Delivery;

final readonly class MysqlNotificationDeliveryRepository implements DeliveryRepositoryInterface
{
    public function __construct(private PdoConnection $database){}

    public function save(Delivery $delivery):void
    {
        $organizationId=trim((string)($delivery->metadata['organization_id']??''));
        if($organizationId==='')throw new InvalidArgumentException('Platform Notification delivery requires organization_id metadata.');

        $this->database->connection()->prepare(
            'INSERT INTO cos_notification_deliveries
             (organization_id,delivery_id,notification_id,channel,recipient,status,attempts,provider_message_id,
              error_message,metadata_json,created_at,delivered_at)
             VALUES(:organization_id,:delivery_id,:notification_id,:channel,:recipient,:status,:attempts,:provider_message_id,
                    :error_message,:metadata_json,:created_at,:delivered_at)
             ON DUPLICATE KEY UPDATE
                status=VALUES(status),attempts=GREATEST(attempts,VALUES(attempts)),
                provider_message_id=COALESCE(VALUES(provider_message_id),provider_message_id),
                error_message=VALUES(error_message),metadata_json=VALUES(metadata_json),
                delivered_at=COALESCE(VALUES(delivered_at),delivered_at)'
        )->execute([
            'organization_id'=>$organizationId,
            'delivery_id'=>$delivery->id,
            'notification_id'=>$delivery->notificationId,
            'channel'=>$delivery->channel->value,
            'recipient'=>$delivery->recipient,
            'status'=>$delivery->status->value,
            'attempts'=>$delivery->attempts,
            'provider_message_id'=>$delivery->providerMessageId,
            'error_message'=>$delivery->error,
            'metadata_json'=>json_encode($delivery->metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'created_at'=>$delivery->createdAt->format('Y-m-d H:i:s.u'),
            'delivered_at'=>$delivery->deliveredAt?->format('Y-m-d H:i:s.u'),
        ]);

        $stored=$this->database->fetchOne(
            'SELECT notification_id,channel,recipient FROM cos_notification_deliveries
             WHERE organization_id=:organization_id AND delivery_id=:delivery_id LIMIT 1',
            ['organization_id'=>$organizationId,'delivery_id'=>$delivery->id],
        );
        if(!$stored)throw new InvalidArgumentException('Platform Notification delivery could not be read back.');
        foreach([
            'notification_id'=>$delivery->notificationId,
            'channel'=>$delivery->channel->value,
            'recipient'=>$delivery->recipient,
        ] as $field=>$expected){
            if((string)($stored[$field]??'')!==$expected){
                throw new InvalidArgumentException('Platform Notification delivery idempotency conflict on '.$field.'.');
            }
        }
    }
}
