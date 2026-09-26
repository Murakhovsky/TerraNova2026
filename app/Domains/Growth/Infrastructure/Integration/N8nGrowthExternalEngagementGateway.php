<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Integration;

use Domains\Growth\Application\Contract\GrowthExternalEngagementGatewayInterface;
use Domains\Growth\Application\DTO\GrowthExternalEngagementDelivery;
use InvalidArgumentException;
use Platform\Integration\Contract\IntegrationOutboxInterface;

final readonly class N8nGrowthExternalEngagementGateway implements GrowthExternalEngagementGatewayInterface
{
    public function __construct(private IntegrationOutboxInterface $outbox) {}

    public function queueLinkedIn(
        string $organizationId,string $recipientProfile,?string $recipientName,string $body,
        string $correlationId,string $idempotencyKey,array $metadata=[]
    ):GrowthExternalEngagementDelivery {
        $recipientProfile=$this->linkedinProfile($recipientProfile);
        return $this->queue(
            'growth.engagement.linkedin',$organizationId,$recipientProfile,$recipientName,$body,
            $correlationId,$idempotencyKey,$metadata,
        );
    }

    public function queueCall(
        string $organizationId,string $recipientPhone,?string $recipientName,string $callBrief,
        string $correlationId,string $idempotencyKey,array $metadata=[]
    ):GrowthExternalEngagementDelivery {
        $recipientPhone=trim($recipientPhone);
        if(!preg_match('/^\+[1-9][0-9]{7,14}$/',$recipientPhone)){
            throw new InvalidArgumentException('Growth call target must be a valid E.164 phone number.');
        }
        return $this->queue(
            'growth.engagement.call',$organizationId,$recipientPhone,$recipientName,$callBrief,
            $correlationId,$idempotencyKey,$metadata,
        );
    }

    /** @param array<string,mixed> $metadata */
    private function queue(
        string $eventType,string $organizationId,string $recipient,?string $recipientName,string $body,
        string $correlationId,string $idempotencyKey,array $metadata
    ):GrowthExternalEngagementDelivery {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $correlationId=$this->bounded($correlationId,'correlationId',191);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $body=trim($body);
        if($body===''||mb_strlen($body)>10000){
            throw new InvalidArgumentException('Growth external engagement body must be 1..10000 characters.');
        }
        $recipientName=$recipientName===null?null:trim($recipientName);
        if($recipientName!==null&&mb_strlen($recipientName)>220){
            throw new InvalidArgumentException('Growth external engagement recipient name is too long.');
        }

        $channel=$eventType==='growth.engagement.linkedin'?'linkedin':'phone';
        $dedupe='n8n:growth:'.$channel.':'.substr(hash('sha256',$organizationId.':'.$idempotencyKey),0,48);
        $deliveryId=$this->outbox->enqueue(
            'n8n',
            $eventType,
            'growth_contact',
            null,
            [
                'organization_id'=>$organizationId,
                'channel'=>$channel,
                'recipient'=>$recipient,
                'recipient_name'=>$recipientName,
                'body'=>$body,
                'correlation_id'=>$correlationId,
                'operation_id'=>$idempotencyKey,
                'metadata'=>$metadata,
            ],
            $dedupe,
        );

        return new GrowthExternalEngagementDelivery($deliveryId,'queued',null);
    }

    private function linkedinProfile(string $value):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>500){
            throw new InvalidArgumentException('Growth LinkedIn target is invalid.');
        }
        $parts=parse_url($value);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'){
            throw new InvalidArgumentException('Growth LinkedIn target must be an HTTPS profile URL.');
        }
        $host=strtolower((string)($parts['host']??''));
        if($host!=='linkedin.com'&&!str_ends_with($host,'.linkedin.com')){
            throw new InvalidArgumentException('Growth LinkedIn target must use linkedin.com.');
        }
        $path=(string)($parts['path']??'');
        if(!preg_match('#^/in/[^/]+/?$#',$path)){
            throw new InvalidArgumentException('Growth LinkedIn target must reference a /in/ profile.');
        }
        return $value;
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }
}
