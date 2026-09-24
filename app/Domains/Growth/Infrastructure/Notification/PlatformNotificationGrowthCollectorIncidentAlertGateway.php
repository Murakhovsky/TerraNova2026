<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Notification;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthCollectorIncidentAlertGatewayInterface;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Notification\Contract\NotificationDispatcherInterface;
use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Notification;
use Platform\Notification\Model\Recipient;

final readonly class PlatformNotificationGrowthCollectorIncidentAlertGateway implements GrowthCollectorIncidentAlertGatewayInterface
{
    public function __construct(private NotificationDispatcherInterface $notifications){}

    public function queue(
        string $organizationId,array $subscription,array $incident,string $transition,string $correlationId
    ):void {
        if(!in_array($transition,['opened','resolved'],true)){
            throw new InvalidArgumentException('Growth collector incident alert transition is invalid.');
        }
        $subscriptionId=trim((string)($subscription['subscription_id']??''));
        $email=mb_strtolower(trim((string)($subscription['recipient_email']??'')));
        $locale=trim((string)($subscription['locale']??'en'));
        $incidentId=trim((string)($incident['incident_id']??''));
        $collector=trim((string)($incident['collector_name']??''));
        if($subscriptionId===''||filter_var($email,FILTER_VALIDATE_EMAIL)===false||$incidentId===''||$collector===''){
            throw new InvalidArgumentException('Growth collector incident alert payload is incomplete.');
        }

        $opened=$transition==='opened';
        $subject=$opened
            ?'[COS Growth] Collector incident: '.$collector
            :'[COS Growth] Collector recovered: '.$collector;
        $body=$opened
            ?sprintf(
                "Collector %s entered an operational incident after %d consecutive failures.\nIncident: %s\nNext retry: %s\nLast error: %s",
                $collector,(int)($incident['failure_count']??0),$incidentId,
                (string)($incident['next_retry_at']??'unknown'),(string)($incident['error_summary']??'unknown'),
            )
            :sprintf(
                "Collector %s recovered and incident %s was resolved.\nFailure count at incident: %d\nResolved at: %s",
                $collector,$incidentId,(int)($incident['failure_count']??0),(string)($incident['resolved_at']??'unknown'),
            );

        $notificationId='GCNT-'.strtoupper(substr(hash(
            'sha256',$organizationId.':'.$subscriptionId.':'.$incidentId.':'.$transition
        ),0,20));

        $this->notifications->send(new Notification(
            $notificationId,
            OrganizationId::fromString($organizationId),
            Channel::EMAIL,
            'growth.collector.incident',
            new Recipient($email,$subscription['recipient_name']??null),
            ['subject'=>$subject,'body'=>$body],
            $locale!==''?$locale:'en',
            $correlationId,
            new DateTimeImmutable(),
            [
                'growth_incident_id'=>$incidentId,
                'growth_collector'=>$collector,
                'growth_transition'=>$transition,
                'growth_subscription_id'=>$subscriptionId,
            ],
        ));
    }
}
