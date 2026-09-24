<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthEngagementDeliveryBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementDeliveryRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\EngagementDeliveryStatus;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthEngagementDeliveryService implements GrowthEngagementDeliveryBoundary
{
    public function __construct(
        private GrowthEngagementExecutionRepositoryInterface $executions,
        private GrowthEngagementDeliveryRepositoryInterface $deliveries,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function recordExternalStatus(
        string $organizationId,
        string $correlationId,
        string $sourceEventId,
        string $actionId,
        string $channel,
        string $status,
        string $occurredAt,
        ?string $providerReference,
        ?string $reasonCode,
        ?string $reasonText,
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $correlationId=$this->bounded($correlationId,'correlationId',191);
        $sourceEventId=$this->bounded($sourceEventId,'sourceEventId',191);
        $actionId=$this->bounded($actionId,'actionId',80);

        $channelEnum=EngagementChannel::tryFrom(strtolower(trim($channel)))
            ?? throw new InvalidArgumentException('Growth delivery channel is invalid.');
        if(!in_array($channelEnum,[EngagementChannel::LinkedIn,EngagementChannel::Phone],true)){
            throw new InvalidArgumentException('Growth external delivery feedback supports LinkedIn or phone only.');
        }

        $statusEnum=EngagementDeliveryStatus::tryFrom(strtolower(trim($status)))
            ?? throw new InvalidArgumentException('Growth delivery status is invalid.');
        if(!$statusEnum->supportsChannel($channelEnum)){
            throw new InvalidArgumentException('Growth delivery status is not valid for this channel.');
        }

        $occurredAt=trim($occurredAt);
        if($occurredAt==='')throw new InvalidArgumentException('Growth delivery occurred_at is required.');
        try{
            $observedAt=new DateTimeImmutable($occurredAt,new DateTimeZone('UTC'));
        }catch(\Throwable){
            throw new InvalidArgumentException('Growth delivery occurred_at is invalid.');
        }

        $providerReference=$this->nullable($providerReference,'providerReference',191);
        $reasonCode=$this->nullable($reasonCode,'reasonCode',120);
        $reasonText=$this->nullable($reasonText,'reasonText',1000);

        $execution=$this->executions->byActionId($organizationId,$actionId)
            ?? throw new InvalidArgumentException('Growth engagement execution was not found for action.');
        if((string)($execution['target_domain']??'')!=='growth'){
            throw new InvalidArgumentException('External pre-handoff delivery feedback requires a Growth-owned execution.');
        }

        $expectedAction=match($channelEnum){
            EngagementChannel::LinkedIn=>'growth.send_linkedin',
            EngagementChannel::Phone=>'growth.place_call',
            default=>'',
        };
        if((string)($execution['action_type']??'')!==$expectedAction||(string)($execution['channel']??'')!==$channelEnum->value){
            throw new InvalidArgumentException('Growth delivery channel does not match the execution Action.');
        }

        $observationId='GEDO-'.strtoupper(substr(hash('sha256',$organizationId.':'.$sourceEventId),0,20));
        $observation=[
            'organization_id'=>$organizationId,
            'observation_id'=>$observationId,
            'source_event_id'=>$sourceEventId,
            'execution_id'=>(string)$execution['execution_id'],
            'candidate_id'=>(string)$execution['candidate_id'],
            'recommendation_id'=>(string)$execution['recommendation_id'],
            'action_id'=>$actionId,
            'channel'=>$channelEnum->value,
            'status'=>$statusEnum->value,
            'terminal'=>$statusEnum->isTerminal(),
            'provider_reference'=>$providerReference,
            'reason_code'=>$reasonCode,
            'reason_text'=>$reasonText,
            'occurred_at'=>$observedAt->format(DATE_ATOM),
        ];

        return $this->transactions->transactional(function()use($observation,$correlationId,$statusEnum):array{
            $stored=$this->deliveries->recordOrVerify($observation);
            if(!empty($stored['replayed']))return $stored;

            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),
                (string)$observation['organization_id'],
                GrowthEventType::ENGAGEMENT_DELIVERY_OBSERVED,
                'growth_candidate',
                (string)$observation['candidate_id'],
                [
                    'observation_id'=>$observation['observation_id'],
                    'execution_id'=>$observation['execution_id'],
                    'recommendation_id'=>$observation['recommendation_id'],
                    'action_id'=>$observation['action_id'],
                    'channel'=>$observation['channel'],
                    'status'=>$observation['status'],
                    'terminal'=>$statusEnum->isTerminal(),
                    'provider_reference'=>$observation['provider_reference'],
                    'reason_code'=>$observation['reason_code'],
                ],
                new EventMetadata($correlationId,null,'SYSTEM','growth-engagement-webhook'),
                $this->now(),
            ));
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),
                (string)$observation['organization_id'],
                'growth.engagement_delivery',
                'SYSTEM',
                'growth-engagement-webhook',
                'growth_candidate',
                (string)$observation['candidate_id'],
                null,
                [
                    'action'=>'growth.engagement.delivery_observed',
                    'source_event_id_hash'=>hash('sha256',(string)$observation['source_event_id']),
                    'result'=>[
                        'observation_id'=>$observation['observation_id'],
                        'execution_id'=>$observation['execution_id'],
                        'action_id'=>$observation['action_id'],
                        'channel'=>$observation['channel'],
                        'status'=>$observation['status'],
                        'terminal'=>$statusEnum->isTerminal(),
                    ],
                ],
                $correlationId,
                $this->now(),
            ));
            return $stored;
        });
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function nullable(?string $value,string $field,int $limit):?string
    {
        if($value===null)return null;
        $value=trim($value);
        if($value==='')return null;
        if(mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is too long.');
        return $value;
    }

    private function now():DateTimeImmutable
    {
        return new DateTimeImmutable('now',new DateTimeZone('UTC'));
    }
}
