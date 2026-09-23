<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthActionProposalGatewayInterface;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\EngagementRecommendationStatus;
use Domains\Growth\Domain\NextBestActionType;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthEngagementExecutionService implements GrowthEngagementExecutionBoundary
{
    /** @var list<string> */
    private const MESSAGE_ACTIONS=[
        'send_email','connect_linkedin','offer_diagnostic','send_case_study','ask_introduction','invite_webinar',
    ];

    public function __construct(
        private GrowthEngagementRepositoryInterface $engagement,
        private GrowthLearningRepositoryInterface $learning,
        private GrowthBuyingCommitteeRepositoryInterface $contacts,
        private GrowthEngagementExecutionRepositoryInterface $executions,
        private GrowthMutationReceiptInterface $receipts,
        private GrowthActionProposalGatewayInterface $actionGateway,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function proposeMessageAction(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,
        string $recommendationId,string $body,string $idempotencyKey
    ):array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $recommendationId=$this->bounded(trim($recommendationId),'recommendationId',80);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $body=trim($body);
        if($body===''||mb_strlen($body)>10000)throw new InvalidArgumentException('Growth engagement execution body must be 1..10000 characters.');

        $recommendation=$this->engagement->viewRecommendation($organizationId,$recommendationId)
            ?? throw new InvalidArgumentException('Growth engagement recommendation was not found.');
        if((string)($recommendation['candidate_id']??'')!==$candidateId){
            throw new InvalidArgumentException('Growth engagement recommendation belongs to another Candidate.');
        }
        if((string)($recommendation['status']??'')!==EngagementRecommendationStatus::Accepted->value){
            throw new InvalidArgumentException('Growth engagement execution requires accepted recommendation.');
        }

        $actionType=(string)($recommendation['action_type']??'');
        $channel=(string)($recommendation['channel']??'');
        if(!in_array($actionType,self::MESSAGE_ACTIONS,true)){
            throw new InvalidArgumentException('Growth engagement recommendation is not executable as Sales message in V0.22.');
        }
        if(!in_array($channel,[EngagementChannel::Email->value,EngagementChannel::LinkedIn->value],true)){
            throw new InvalidArgumentException('Growth engagement execution currently supports email or LinkedIn message channels only.');
        }

        $deals=$this->learning->externalSubjectsForCandidate($organizationId,$candidateId,'sales','sales_deal');
        if(count($deals)>1){
            throw new InvalidArgumentException('Growth engagement execution has ambiguous sales_deal bindings; found '.count($deals).'.');
        }

        $targetDomain='';
        $targetReferenceType='';
        $targetReferenceId='';
        $kernelActionType='';
        $confidence=isset($recommendation['confidence'])?(float)$recommendation['confidence']:null;
        $kernelIdempotency='growth-engagement-'.substr(hash('sha256',$organizationId.':'.$recommendationId),0,40);

        if(count($deals)===1){
            $targetDomain='sales';
            $targetReferenceType='sales_deal';
            $targetReferenceId=$deals[0];
            $kernelActionType='sales.send_message';
        }else{
            if($channel!==EngagementChannel::Email->value){
                throw new InvalidArgumentException('Pre-handoff Growth execution currently supports email only.');
            }
            $contactId=trim((string)($recommendation['contact_id']??''));
            if($contactId===''){
                throw new InvalidArgumentException('Pre-handoff Growth execution requires recommendation contact_id.');
            }
            if(!$this->hasUsableEmailContact($organizationId,$contactId)){
                throw new InvalidArgumentException('Pre-handoff Growth execution requires a contact with valid email identity.');
            }
            $targetDomain='growth';
            $targetReferenceType='growth_contact';
            $targetReferenceId=$contactId;
            $kernelActionType='growth.send_message';
        }

        $payloadFingerprint=hash('sha256',json_encode([
            'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,
            'target_domain'=>$targetDomain,'target_reference_type'=>$targetReferenceType,'target_reference_id'=>$targetReferenceId,
            'action_type'=>$kernelActionType,'channel'=>$channel,'body'=>$body,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $this->receipts->claim(
            $organizationId,'engagement_execution_payload',$recommendationId,$payloadFingerprint,
        );

        $existing=$this->executions->byRecommendation($organizationId,$recommendationId);
        if($existing!==null){
            if((string)$existing['payload_fingerprint']!==$payloadFingerprint){
                throw new InvalidArgumentException('Growth engagement recommendation already has another execution payload.');
            }
            $action=$this->actionGateway->find($organizationId,(string)$existing['action_id'])
                ?? throw new InvalidArgumentException('Growth engagement execution references missing Kernel Action.');
            return ['execution'=>$existing,'action'=>$action->toArray(),'replayed'=>true];
        }

        $action=$kernelActionType==='sales.send_message'
            ? $this->actionGateway->proposeSalesMessage(
                $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$targetReferenceId,$channel,$body,
                $confidence,$kernelIdempotency,
            )
            : $this->actionGateway->proposeGrowthMessage(
                $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$targetReferenceId,$channel,$body,
                $confidence,$kernelIdempotency,
            );
        $executionId='GEXE-'.strtoupper(substr(hash('sha256',$organizationId.':'.$recommendationId),0,20));

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$targetDomain,$targetReferenceType,
            $targetReferenceId,$channel,$payloadFingerprint,$executionId,$action,$idempotencyKey
        ):array{
            $this->executions->createOrVerify(
                $organizationId,$executionId,$candidateId,$recommendationId,$targetDomain,$targetReferenceType,$targetReferenceId,
                $action->id,$action->type,$channel,$payloadFingerprint,$actorId,
            );
            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),$organizationId,GrowthEventType::ENGAGEMENT_EXECUTION_PROPOSED,
                'growth_candidate',$candidateId,[
                    'execution_id'=>$executionId,'recommendation_id'=>$recommendationId,
                    'action_id'=>$action->id,'action_type'=>$action->type,'action_status'=>$action->status,
                    'target_domain'=>$targetDomain,'target_reference_type'=>$targetReferenceType,'target_reference_id'=>$targetReferenceId,
                    'channel'=>$channel,
                ],
                new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
            ));
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),$organizationId,'growth.engagement_execution','USER',(string)$actorId,
                'growth_candidate',$candidateId,null,[
                    'action'=>'growth.engagement.execution_proposed',
                    'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                    'input_references'=>[
                        'recommendation_id'=>$recommendationId,
                        'target_domain'=>$targetDomain,
                        'target_reference_type'=>$targetReferenceType,
                        'target_reference_id'=>$targetReferenceId,
                    ],
                    'result'=>['execution_id'=>$executionId,'action_id'=>$action->id,'status'=>$action->status],
                ],$correlationId,$this->now(),
            ));
            $link=$this->executions->byRecommendation($organizationId,$recommendationId)
                ?? throw new InvalidArgumentException('Growth engagement execution link could not be read back.');
            return ['execution'=>$link,'action'=>$action->toArray()];
        });
    }

    public function executionBrief(string $organizationId,string $candidateId,string $recommendationId):array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $recommendationId=$this->bounded(trim($recommendationId),'recommendationId',80);
        $recommendation=$this->engagement->viewRecommendation($organizationId,$recommendationId)
            ?? throw new InvalidArgumentException('Growth engagement recommendation was not found.');
        if((string)($recommendation['candidate_id']??'')!==$candidateId){
            throw new InvalidArgumentException('Growth engagement recommendation belongs to another Candidate.');
        }
        $execution=$this->executions->byRecommendation($organizationId,$recommendationId);
        $action=null;
        if($execution!==null){
            $stored=$this->actionGateway->find($organizationId,(string)$execution['action_id']);
            $action=$stored?->toArray();
        }

        return [
            'recommendation'=>$recommendation,
            'execution'=>$execution,
            'action'=>$action,
            'eligibility'=>$this->executionEligibility($organizationId,$candidateId,$recommendation,$execution),
        ];
    }

    /** @param array<string,mixed> $recommendation @param array<string,mixed>|null $execution @return array<string,mixed> */
    private function executionEligibility(
        string $organizationId,string $candidateId,array $recommendation,?array $execution
    ):array {
        if($execution!==null){
            return [
                'can_propose'=>false,
                'code'=>'already_proposed',
                'reason'=>'This recommendation already has a governed execution Action.',
                'target_reference_type'=>$execution['target_reference_type']??null,
                'target_reference_id'=>$execution['target_reference_id']??null,
            ];
        }

        if((string)($recommendation['status']??'')!==EngagementRecommendationStatus::Accepted->value){
            return ['can_propose'=>false,'code'=>'recommendation_not_accepted','reason'=>'Accept the recommendation before proposing execution.'];
        }

        $actionType=(string)($recommendation['action_type']??'');
        if(!in_array($actionType,self::MESSAGE_ACTIONS,true)){
            return ['can_propose'=>false,'code'=>'action_not_message_capable','reason'=>'This recommendation is not message-capable in the current execution bridge.'];
        }

        $channel=(string)($recommendation['channel']??'');
        if(!in_array($channel,[EngagementChannel::Email->value,EngagementChannel::LinkedIn->value],true)){
            return ['can_propose'=>false,'code'=>'channel_not_supported','reason'=>'Current execution bridge supports email or LinkedIn message channels only.'];
        }

        $deals=$this->learning->externalSubjectsForCandidate($organizationId,$candidateId,'sales','sales_deal');
        if(count($deals)>1){
            return [
                'can_propose'=>false,
                'code'=>'ambiguous_sales_deal_binding',
                'reason'=>'Execution has ambiguous sales_deal bindings; found '.count($deals).'.',
            ];
        }

        if(count($deals)===1){
            return [
                'can_propose'=>true,
                'code'=>'eligible_post_handoff',
                'reason'=>'Accepted recommendation is eligible for governed Sales message proposal.',
                'target_domain'=>'sales',
                'target_reference_type'=>'sales_deal',
                'target_reference_id'=>$deals[0],
                'action_type'=>'sales.send_message',
                'channel'=>$channel,
            ];
        }

        if($channel!==EngagementChannel::Email->value){
            return [
                'can_propose'=>false,
                'code'=>'pre_handoff_channel_not_supported',
                'reason'=>'Pre-handoff execution currently supports email only.',
            ];
        }
        $contactId=trim((string)($recommendation['contact_id']??''));
        if($contactId===''){
            return [
                'can_propose'=>false,
                'code'=>'pre_handoff_contact_required',
                'reason'=>'Pre-handoff execution requires an explicit recommendation contact.',
            ];
        }
        if(!$this->hasUsableEmailContact($organizationId,$contactId)){
            return [
                'can_propose'=>false,
                'code'=>'pre_handoff_contact_email_required',
                'reason'=>'Pre-handoff execution requires a Growth contact with valid email identity.',
            ];
        }

        return [
            'can_propose'=>true,
            'code'=>'eligible_pre_handoff',
            'reason'=>'Accepted recommendation is eligible for governed pre-handoff Growth email proposal.',
            'target_domain'=>'growth',
            'target_reference_type'=>'growth_contact',
            'target_reference_id'=>$contactId,
            'action_type'=>'growth.send_message',
            'channel'=>$channel,
        ];
    }

    private function hasUsableEmailContact(string $organizationId,string $contactId):bool
    {
        $contact=$this->contacts->viewContact($organizationId,$contactId);
        if($contact===null)return false;
        if(strtolower(trim((string)($contact['identity_type']??'')))!=='email')return false;
        $email=trim((string)($contact['identity_value']??''));
        return filter_var($email,FILTER_VALIDATE_EMAIL)!==false;
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
