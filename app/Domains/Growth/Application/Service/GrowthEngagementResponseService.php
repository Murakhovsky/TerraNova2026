<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementResponseBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementResponseRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\GrowthOutcomeObservation;
use Domains\Growth\Domain\GrowthOutcomeType;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthEngagementResponseService implements GrowthEngagementResponseBoundary
{
    public function __construct(
        private GrowthEngagementExecutionRepositoryInterface $executions,
        private GrowthEngagementResponseRepositoryInterface $responses,
        private GrowthLearningRepositoryInterface $learning,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function recordExternalResponse(
        string $organizationId,string $correlationId,string $sourceEventId,string $actionId,string $channel,
        string $body,string $occurredAt,?string $providerReference,?string $threadReference
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $correlationId=$this->bounded($correlationId,'correlationId',191);
        $sourceEventId=$this->bounded($sourceEventId,'sourceEventId',191);
        $actionId=$this->bounded($actionId,'actionId',80);
        $body=trim($body);
        if($body===''||mb_strlen($body)>20000)throw new InvalidArgumentException('Growth response body must be between 1 and 20000 characters.');

        $channelEnum=EngagementChannel::tryFrom(strtolower(trim($channel)))
            ??throw new InvalidArgumentException('Growth response channel is invalid.');
        if(!in_array($channelEnum,[EngagementChannel::Email,EngagementChannel::LinkedIn,EngagementChannel::Phone],true)){
            throw new InvalidArgumentException('Growth response supports email, LinkedIn or phone only.');
        }

        $occurredAt=trim($occurredAt);
        if($occurredAt==='')throw new InvalidArgumentException('Growth response occurred_at is required.');
        try{$observedAt=(new DateTimeImmutable($occurredAt,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));}
        catch(\Throwable){throw new InvalidArgumentException('Growth response occurred_at is invalid.');}

        $providerReference=$this->nullable($providerReference,'providerReference',191);
        $threadReference=$this->nullable($threadReference,'threadReference',191);

        return $this->transactions->transactional(function()use(
            $organizationId,$correlationId,$sourceEventId,$actionId,$channelEnum,$body,$observedAt,$providerReference,$threadReference
        ):array{
            $this->executions->lockPreHandoffCapacity($organizationId);
            $execution=$this->executions->byActionId($organizationId,$actionId)
                ??throw new InvalidArgumentException('Growth engagement execution was not found for response action.');
            if((string)($execution['target_domain']??'')!=='growth'){
                throw new InvalidArgumentException('Inbound Growth response requires a Growth-owned pre-handoff execution.');
            }

            $expectedAction=match($channelEnum){
                EngagementChannel::Email=>'growth.send_message',
                EngagementChannel::LinkedIn=>'growth.send_linkedin',
                EngagementChannel::Phone=>'growth.place_call',
                default=>'',
            };
            if((string)($execution['action_type']??'')!==$expectedAction||(string)($execution['channel']??'')!==$channelEnum->value){
                throw new InvalidArgumentException('Growth response channel does not match the execution Action.');
            }

            $responseId='GER-'.strtoupper(substr(hash('sha256',$organizationId.':'.$sourceEventId),0,20));
            $response=[
                'organization_id'=>$organizationId,'response_id'=>$responseId,'source_event_id'=>$sourceEventId,
                'execution_id'=>(string)$execution['execution_id'],'candidate_id'=>(string)$execution['candidate_id'],
                'recommendation_id'=>(string)$execution['recommendation_id'],'action_id'=>$actionId,
                'channel'=>$channelEnum->value,'body'=>$body,'body_hash'=>hash('sha256',$body),
                'provider_reference'=>$providerReference,'thread_reference'=>$threadReference,
                'occurred_at'=>$observedAt->format(DATE_ATOM),
            ];
            $stored=$this->responses->recordOrVerify($response);
            if(!empty($stored['replayed']))return $stored;

            $outcomeSourceEventId='engagement-response:'.hash('sha256',$sourceEventId);
            $outcome=new GrowthOutcomeObservation(
                'GOUT-'.strtoupper(substr(hash('sha256',$organizationId.':'.$outcomeSourceEventId),0,20)),
                OrganizationId::fromString($organizationId),
                (string)$execution['candidate_id'],
                'growth-engagement',
                $outcomeSourceEventId,
                'growth_engagement_response',
                $responseId,
                GrowthOutcomeType::ReplyReceived,
                'inbound_reply',
                null,
                null,
                null,
                $observedAt,
            );
            $this->learning->recordOutcome($outcome);

            $responseEventId=bin2hex(random_bytes(16));
            $metadata=new EventMetadata($correlationId,null,'SYSTEM','growth-response-webhook');
            $this->events->publish(new DomainEvent(
                $responseEventId,$organizationId,GrowthEventType::ENGAGEMENT_RESPONSE_RECEIVED,
                'growth_candidate',(string)$execution['candidate_id'],[
                    'response_id'=>$responseId,'execution_id'=>$execution['execution_id'],
                    'recommendation_id'=>$execution['recommendation_id'],'action_id'=>$actionId,
                    'channel'=>$channelEnum->value,'body_hash'=>$response['body_hash'],
                    'provider_reference'=>$providerReference,'thread_reference'=>$threadReference,
                    'occurred_at'=>$observedAt->format(DATE_ATOM),
                ],$metadata,$this->now(),
            ));
            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),$organizationId,GrowthEventType::OUTCOME_RECORDED,
                'growth_candidate',(string)$execution['candidate_id'],$outcome->toArray(),
                new EventMetadata($correlationId,$responseEventId,'SYSTEM','growth-response-webhook'),$this->now(),
            ));
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),$organizationId,'growth.engagement_response','SYSTEM','growth-response-webhook',
                'growth_candidate',(string)$execution['candidate_id'],null,[
                    'action'=>'growth.engagement.response_received',
                    'source_event_id_hash'=>hash('sha256',$sourceEventId),
                    'input_references'=>[
                        'execution_id'=>$execution['execution_id'],'recommendation_id'=>$execution['recommendation_id'],'action_id'=>$actionId,
                    ],
                    'result'=>[
                        'response_id'=>$responseId,'channel'=>$channelEnum->value,'body_hash'=>$response['body_hash'],
                        'occurred_at'=>$observedAt->format(DATE_ATOM),
                    ],
                ],$correlationId,$this->now(),
            ));

            return $stored;
        });
    }

    public function responseBrief(string $organizationId,string $candidateId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $candidateId=$this->bounded($candidateId,'candidateId',80);
        $responses=[];
        foreach($this->responses->latestForCandidate($organizationId,$candidateId,20) as $response){
            $response['classification']=$this->responses->latestClassification($organizationId,(string)$response['response_id']);
            $responses[]=$response;
        }
        return ['candidate_id'=>$candidateId,'responses'=>$responses,'count'=>count($responses)];
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

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
