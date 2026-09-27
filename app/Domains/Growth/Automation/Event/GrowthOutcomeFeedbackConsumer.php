<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Event;

use Domains\Growth\Application\Contract\GrowthHandoffRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
use Domains\Growth\Domain\GrowthOutcomeObservation;
use Domains\Growth\Domain\GrowthOutcomeType;
use Domains\Sales\Automation\Event\LeadChanged;
use Domains\Sales\Automation\Event\SalesEventType;
use Domains\Sales\Model\LeadStatus;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthOutcomeFeedbackConsumer implements DurableEventConsumerInterface
{
    public function __construct(
        private GrowthHandoffRepositoryInterface $handoffs,
        private GrowthLearningRepositoryInterface $learning,
        private ActiveModuleResolver $modules,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function consumerName():string{return 'growth.outcome-feedback.v1';}

    public function handle(DomainEvent $event):void
    {
        if(!$this->modules->isEnabled($event->organizationId,'growth'))return;
        if(!$this->isSupported($event))return;

        $this->transactions->transactional(function()use($event):void{
            $candidateId=$this->resolveCandidate($event);
            if($candidateId===null)return;

            if($event->type===LeadChanged::TYPE){
                $this->bindConvertedDeal($event,$candidateId);
            }

            $outcomeType=$this->outcomeType($event);
            if($outcomeType===null)return;

            $reasonCode=$this->reasonCode($event);
            $reasonText=$this->reasonText($event);
            [$economicValue,$currency]=$this->economicValue($event);
            $referenceType=$this->referenceType($event)
                ?? throw new InvalidArgumentException('Supported Growth feedback event has unsupported aggregate type.');
            $outcomeId='GOUT-'.strtoupper(substr(hash('sha256',$event->organizationId.':'.$event->id),0,20));

            $outcome=new GrowthOutcomeObservation(
                $outcomeId,OrganizationId::fromString($event->organizationId),$candidateId,'sales',$event->id,
                $referenceType,$event->aggregateId,$outcomeType,$reasonCode,$reasonText,$economicValue,$currency,$event->occurredAt,
            );
            $this->learning->recordOutcome($outcome);

            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),$event->organizationId,GrowthEventType::OUTCOME_RECORDED,
                'growth_candidate',$candidateId,$outcome->toArray(),
                new EventMetadata($event->metadata->correlationId,$event->id,'SYSTEM',$this->consumerName()),
                new \DateTimeImmutable(),
            ));
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),$event->organizationId,'growth.learning','SYSTEM',$this->consumerName(),
                'growth_candidate',$candidateId,null,[
                    'action'=>'growth.outcome.recorded',
                    'input_references'=>['source_event_id'=>$event->id,'source_event_type'=>$event->type],
                    'result'=>[
                        'outcome_id'=>$outcomeId,'outcome_type'=>$outcomeType->value,
                        'reference_type'=>$referenceType,'reference_id'=>$event->aggregateId,
                    ],
                ],$event->metadata->correlationId,new \DateTimeImmutable(),
            ));
        });
    }

    private function isSupported(DomainEvent $event):bool
    {
        return $event->type===LeadChanged::TYPE||in_array($event->type,[
            SalesEventType::LEAD_CONTACTED,
            SalesEventType::LEAD_QUALIFIED,
            SalesEventType::LEAD_DISQUALIFIED,
            SalesEventType::MESSAGE_RECEIVED,
            SalesEventType::MEETING_COMPLETED,
            SalesEventType::DEAL_WON,
            SalesEventType::DEAL_LOST,
        ],true);
    }

    private function resolveCandidate(DomainEvent $event):?string
    {
        $referenceType=$this->referenceType($event);
        if($referenceType===null)return null;
        $candidate=$this->learning->candidateByExternalSubject(
            $event->organizationId,'sales',$referenceType,$event->aggregateId,
        );
        if($candidate!==null)return $candidate;

        if($referenceType!=='sales_lead')return null;
        $candidate=$this->handoffs->candidateByTargetReference(
            $event->organizationId,'sales','sales_lead',$event->aggregateId,
        );
        if($candidate===null)return null;

        $this->learning->bindExternalSubject(
            $event->organizationId,$candidate,'sales','sales_lead',$event->aggregateId,$event->id,
        );
        return $candidate;
    }

    private function bindConvertedDeal(DomainEvent $event,string $candidateId):void
    {
        $changes=$event->payload['changes']??null;
        if(!is_array($changes))return;
        $caseChange=$changes['client_case_id']??null;
        if(!is_array($caseChange))return;
        $to=$caseChange['to']??null;
        if(!is_int($to)&&!(is_string($to)&&ctype_digit($to)))return;
        if((int)$to<1)return;

        $this->learning->bindExternalSubject(
            $event->organizationId,$candidateId,'sales','sales_deal',(string)(int)$to,$event->id,
        );
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$event->organizationId,GrowthEventType::LEARNING_BINDING_CREATED,
            'growth_candidate',$candidateId,[
                'source_domain'=>'sales','reference_type'=>'sales_deal','reference_id'=>(string)(int)$to,
                'source_event_id'=>$event->id,
            ],
            new EventMetadata($event->metadata->correlationId,$event->id,'SYSTEM',$this->consumerName()),
            new \DateTimeImmutable(),
        ));
    }

    private function referenceType(DomainEvent $event):?string
    {
        return match($event->aggregateType){
            'lead'=>'sales_lead',
            'deal'=>'sales_deal',
            default=>null,
        };
    }

    private function outcomeType(DomainEvent $event):?GrowthOutcomeType
    {
        if($event->type===SalesEventType::LEAD_CONTACTED)return GrowthOutcomeType::Contacted;
        if($event->type===SalesEventType::LEAD_QUALIFIED)return GrowthOutcomeType::Qualified;
        if($event->type===SalesEventType::LEAD_DISQUALIFIED)return GrowthOutcomeType::Disqualified;
        if($event->type===SalesEventType::MESSAGE_RECEIVED)return GrowthOutcomeType::ReplyReceived;
        if($event->type===SalesEventType::MEETING_COMPLETED)return GrowthOutcomeType::MeetingCompleted;
        if($event->type===SalesEventType::DEAL_WON)return GrowthOutcomeType::Won;
        if($event->type===SalesEventType::DEAL_LOST)return GrowthOutcomeType::Lost;

        if($event->type!==LeadChanged::TYPE)return null;
        $changes=$event->payload['changes']??null;
        if(!is_array($changes))return null;
        $status=$changes['status']??null;
        if(!is_array($status))return null;
        $to=(string)($status['to']??'');
        return match($to){
            LeadStatus::Contacted->value=>GrowthOutcomeType::Contacted,
            LeadStatus::Qualified->value=>GrowthOutcomeType::Qualified,
            LeadStatus::Disqualified->value=>GrowthOutcomeType::Disqualified,
            default=>null,
        };
    }

    private function reasonCode(DomainEvent $event):?string
    {
        foreach(['reason_code','lost_reason_id','reason'] as $key){
            $value=$event->payload[$key]??null;
            if(is_string($value)&&trim($value)!=='')return mb_substr(trim($value),0,120);
        }
        return null;
    }

    private function reasonText(DomainEvent $event):?string
    {
        foreach(['reason_text','lost_reason_note'] as $key){
            $value=$event->payload[$key]??null;
            if(is_string($value)&&trim($value)!=='')return mb_substr(trim($value),0,2000);
        }
        return null;
    }

    /** @return array{0:?float,1:?string} */
    private function economicValue(DomainEvent $event):array
    {
        if($event->type!==SalesEventType::DEAL_WON)return [null,null];
        $value=$event->payload['deal_value']??null;
        $currency=$event->payload['currency']??null;
        if((!is_int($value)&&!is_float($value))||$value<0)return [null,null];
        if(!is_string($currency)||!preg_match('/^[A-Z]{3,8}$/',trim($currency)))return [null,null];
        return [(float)$value,trim($currency)];
    }
}
