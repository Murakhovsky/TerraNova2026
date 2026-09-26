<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Notification;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthOutboundMessageGatewayInterface;
use Domains\Growth\Application\DTO\GrowthOutboundDelivery;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Notification\Contract\NotificationDispatcherInterface;
use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Notification;
use Platform\Notification\Model\Recipient;

final readonly class PlatformNotificationGrowthOutboundMessageGateway implements GrowthOutboundMessageGatewayInterface
{
    public function __construct(private NotificationDispatcherInterface $notifications){}

    public function queueEmail(
        string $organizationId,string $recipientAddress,?string $recipientName,string $body,?string $subject,
        string $locale,string $correlationId,string $idempotencyKey,array $metadata=[]
    ):GrowthOutboundDelivery {
        $notificationId='GNOT-'.strtoupper(substr(hash('sha256',$organizationId.':'.$idempotencyKey),0,20));
        $delivery=$this->notifications->send(new Notification(
            $notificationId,
            OrganizationId::fromString($organizationId),
            Channel::EMAIL,
            'growth.outbound.message',
            new Recipient($recipientAddress,$recipientName),
            ['subject'=>$subject??'','body'=>$body],
            $locale,
            $correlationId,
            new DateTimeImmutable(),
            $metadata,
        ));

        return new GrowthOutboundDelivery(
            $notificationId,$delivery->id,$delivery->status->value,$delivery->providerMessageId,
        );
    }
}
