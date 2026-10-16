<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DateTimeImmutable;
use DomainException;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Notification\Contract\NotificationDispatcherInterface;
use Platform\Notification\Model\{Channel,DeliveryStatus,Notification,Recipient};

/** Delivery via existing persistent Notification/outbox, not direct HTTP. */
final readonly class StreamingIncidentDelivery
{
    public function __construct(private NotificationDispatcherInterface $dispatcher){}

    /** @param array<string,mixed> $incident */
    public function queue(string $org,string $email,array $incident):void
    {
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$org===''||strlen($org)>190
            ||(int)($incident['id']??0)<1){
            throw new DomainException('Invalid operational incident alert destination.');
        }
        $id=(string)$incident['id'];
        $source=(string)$incident['source_id'];
        $code=(string)$incident['code'];
        if($source===''||strlen($source)>190
            ||preg_match('/^[A-Z0-9_]+$/D',$code)!==1){
            throw new DomainException('Unsafe incident content.');
        }
        $recipient=strtolower($email);
        $key=hash('sha256',implode('|',[$org,$recipient,$id,$incident['first_seen_at']]));
        $notice=new Notification(
            'CMOPS-'.strtoupper(substr($key,0,26)),
            OrganizationId::fromString($org),
            Channel::EMAIL,
            'capital_markets.streaming.incident',
            new Recipient($recipient),
            [
                'subject'=>'[COS CM] '.($incident['severity']??'WARNING').' '.$code,
                'body'=>"Capital Markets streaming operational incident\n"
                    ."Tenant: ".$org."\nSource: ".$source
                    ."\nSubject: ".($incident['subject_id']??'')
                    ."\nCode: ".$code."\nIncident ID: ".$id
                    ."\nNotification QUEUED is not proof of final delivery.",
            ],
            'uk',
            'cm-stream-incident-'.$id,
            new DateTimeImmutable(),
            ['stream_incident_id'=>$id,'source_id'=>$source,'code'=>$code],
        );
        if($this->dispatcher->send($notice)->status===DeliveryStatus::FAILED){
            throw new DomainException('Streaming incident notification outbox rejected message.');
        }
    }
}
