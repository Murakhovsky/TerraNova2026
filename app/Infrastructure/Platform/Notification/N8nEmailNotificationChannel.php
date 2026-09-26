<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Notification;

use DateTimeImmutable;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use InvalidArgumentException;
use Platform\Notification\Contract\NotificationChannelInterface;
use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Delivery;
use Platform\Notification\Model\DeliveryStatus;
use Platform\Notification\Model\Notification;
use Platform\Notification\Model\RenderedNotification;
use RuntimeException;

final readonly class N8nEmailNotificationChannel implements NotificationChannelInterface
{
    public function __construct(private PdoConnection $database){}

    public function channel():Channel{return Channel::EMAIL;}

    public function deliver(Notification $notification,RenderedNotification $rendered):Delivery
    {
        if($notification->channel!==Channel::EMAIL)throw new InvalidArgumentException('N8n email channel received another notification channel.');
        $recipient=trim($notification->recipient->address);
        if(filter_var($recipient,FILTER_VALIDATE_EMAIL)===false){
            throw new InvalidArgumentException('Platform Notification email recipient is invalid.');
        }
        $body=trim($rendered->body);
        if($body===''||mb_strlen($body)>10000)throw new InvalidArgumentException('Platform Notification email body must be 1..10000 characters.');
        $subject=$rendered->subject===null?null:trim($rendered->subject);
        if($subject!==null&&mb_strlen($subject)>250)throw new InvalidArgumentException('Platform Notification email subject is too long.');

        $payload=[
            'organization_id'=>$notification->organizationId->value(),
            'notification_id'=>$notification->id,
            'channel'=>'email',
            'recipient'=>[
                'address'=>$recipient,
                'name'=>$notification->recipient->name,
            ],
            'subject'=>$subject,
            'body'=>$body,
            'locale'=>$notification->locale,
            'correlation_id'=>$notification->correlationId,
            'metadata'=>$notification->metadata,
        ];
        $dedupeKey='notification:'.$notification->id;
        $fingerprint=$this->fingerprint($payload);
        $encoded=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $statement=$this->database->connection()->prepare(
            'INSERT IGNORE INTO tn_integration_outbox
             (integration,event_type,entity_type,entity_id,payload,dedupe_key)
             VALUES(\'n8n\',\'notification.email\',\'notification\',NULL,:payload,:dedupe_key)'
        );
        $statement->execute(['payload'=>$encoded,'dedupe_key'=>$dedupeKey]);

        $stored=$this->database->fetchOne(
            'SELECT id,payload,status,attempts,sent_at,last_error
             FROM tn_integration_outbox WHERE dedupe_key=:dedupe_key LIMIT 1',
            ['dedupe_key'=>$dedupeKey],
        );
        if(!$stored)throw new RuntimeException('Platform Notification n8n outbox row could not be read back.');
        $storedPayload=json_decode((string)$stored['payload'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($storedPayload)||$this->fingerprint($storedPayload)!==$fingerprint){
            throw new InvalidArgumentException('Platform Notification idempotency conflict for '.$notification->id.'.');
        }

        $status=match((string)$stored['status']){
            'sent'=>DeliveryStatus::SENT,
            'failed'=>DeliveryStatus::FAILED,
            default=>DeliveryStatus::QUEUED,
        };
        $error=$status===DeliveryStatus::FAILED
            ? trim((string)($stored['last_error']??'')) ?: 'n8n notification delivery failed.'
            : null;
        $sentAt=!empty($stored['sent_at'])?new DateTimeImmutable((string)$stored['sent_at']):null;

        return new Delivery(
            'NDLV-'.strtoupper(substr(hash('sha256',$notification->organizationId->value().':'.$notification->id),0,20)),
            $notification->id,
            Channel::EMAIL,
            $recipient,
            $status,
            (int)($stored['attempts']??0),
            $notification->createdAt,
            $sentAt,
            'n8n-outbox-'.(string)$stored['id'],
            $error,
            [
                'organization_id'=>$notification->organizationId->value(),
                'outbox_id'=>(int)$stored['id'],
                'integration'=>'n8n',
                'event_type'=>'notification.email',
            ],
        );
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value):string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode($normalize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
