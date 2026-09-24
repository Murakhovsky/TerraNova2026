<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthActionProposalGatewayInterface;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementDeliveryRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementLimitProviderInterface;
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
    private const EXECUTABLE_ACTIONS=[
        'send_email','connect_linkedin','call','offer_diagnostic','send_case_study','ask_introduction','invite_webinar',
    ];

    public function __construct(
        private GrowthEngagementRepositoryInterface $engagement,
        private GrowthLearningRepositoryInterface $learning,
        private GrowthBuyingCommitteeRepositoryInterface $contacts,
        private GrowthEngagementExecutionRepositoryInterface $executions,
        private GrowthEngagementDeliveryRepositoryInterface $deliveries,
        private GrowthEngagementLimitProviderInterface $limitProvider,
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
        if($body===''||mb_strlen($body)>10000){
            throw new InvalidArgumentException('Growth engagement execution body must be 1..10000 characters.');
        }

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
        $this->assertExecutableRecommendation($actionType,$channel);

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
            if($channel===EngagementChannel::Phone->value){
                throw new InvalidArgumentException('Post-handoff call execution belongs to Sales and is not available through the Growth pre-handoff bridge.');
            }
            $targetDomain='sales';
            $targetReferenceType='sales_deal';
            $targetReferenceId=$deals[0];
            $kernelActionType='sales.send_message';
        }else{
            $contactId=trim((string)($recommendation['contact_id']??''));
            if($contactId===''){
                throw new InvalidArgumentException('Pre-handoff Growth execution requires recommendation contact_id.');
            }
            if(!$this->hasUsableChannelIdentity($organizationId,$contactId,$channel)){
                throw new InvalidArgumentException('Pre-handoff Growth execution requires a contact with usable '.$channel.' identity.');
            }
            $limitDecision=$this->preHandoffLimitDecision($organizationId,$contactId,$channel);
            if(!$limitDecision['allowed']){
                throw new InvalidArgumentException((string)$limitDecision['reason']);
            }
            $targetDomain='growth';
            $targetReferenceType='growth_contact';
            $targetReferenceId=$contactId;
            $kernelActionType=match($channel){
                EngagementChannel::Email->value=>'growth.send_message',
                EngagementChannel::LinkedIn->value=>'growth.send_linkedin',
                EngagementChannel::Phone->value=>'growth.place_call',
                default=>throw new InvalidArgumentException('Unsupported pre-handoff Growth engagement channel.'),
            };
        }

        $payloadFingerprint=hash('sha256',json_encode([
            'candidate_id'=>$candidateId,
            'recommendation_id'=>$recommendationId,
            'target_domain'=>$targetDomain,
            'target_reference_type'=>$targetReferenceType,
            'target_reference_id'=>$targetReferenceId,
            'action_type'=>$kernelActionType,
            'channel'=>$channel,
            'body'=>$body,
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

        $action=match($kernelActionType){
            'sales.send_message'=>$this->actionGateway->proposeSalesMessage(
                $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$targetReferenceId,$channel,$body,
                $confidence,$kernelIdempotency,
            ),
            'growth.send_message'=>$this->actionGateway->proposeGrowthMessage(
                $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$targetReferenceId,$channel,$body,
                $confidence,$kernelIdempotency,
            ),
            'growth.send_linkedin'=>$this->actionGateway->proposeGrowthLinkedIn(
                $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$targetReferenceId,$body,
                $confidence,$kernelIdempotency,
            ),
            'growth.place_call'=>$this->actionGateway->proposeGrowthCall(
                $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$targetReferenceId,$body,
                $confidence,$kernelIdempotency,
            ),
            default=>throw new InvalidArgumentException('Unsupported Growth engagement action type.'),
        };
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
                    'execution_id'=>$executionId,
                    'recommendation_id'=>$recommendationId,
                    'action_id'=>$action->id,
                    'action_type'=>$action->type,
                    'action_status'=>$action->status,
                    'target_domain'=>$targetDomain,
                    'target_reference_type'=>$targetReferenceType,
                    'target_reference_id'=>$targetReferenceId,
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
        $deliveryObservations=[];
        $latestDelivery=null;
        if($execution!==null){
            $stored=$this->actionGateway->find($organizationId,(string)$execution['action_id']);
            $action=$stored?->toArray();
            $deliveryObservations=$this->deliveries->forExecution($organizationId,(string)$execution['execution_id'],20);
            $latestDelivery=$deliveryObservations[0]??null;
        }

        return [
            'recommendation'=>$recommendation,
            'execution'=>$execution,
            'action'=>$action,
            'delivery_observations'=>$deliveryObservations,
            'latest_delivery'=>$latestDelivery,
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
        $channel=(string)($recommendation['channel']??'');
        try{
            $this->assertExecutableRecommendation($actionType,$channel);
        }catch(InvalidArgumentException $error){
            return ['can_propose'=>false,'code'=>'channel_not_supported','reason'=>$error->getMessage()];
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
            if($channel===EngagementChannel::Phone->value){
                return [
                    'can_propose'=>false,
                    'code'=>'post_handoff_call_not_supported',
                    'reason'=>'Post-handoff call execution belongs to Sales; Growth only owns the pre-handoff call bridge.',
                ];
            }
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

        $contactId=trim((string)($recommendation['contact_id']??''));
        if($contactId===''){
            return [
                'can_propose'=>false,
                'code'=>'pre_handoff_contact_required',
                'reason'=>'Pre-handoff execution requires an explicit recommendation contact.',
            ];
        }
        if(!$this->hasUsableChannelIdentity($organizationId,$contactId,$channel)){
            return [
                'can_propose'=>false,
                'code'=>'pre_handoff_contact_identity_required',
                'reason'=>'Pre-handoff execution requires a Growth contact with usable '.$channel.' identity.',
            ];
        }
        $limitDecision=$this->preHandoffLimitDecision($organizationId,$contactId,$channel);
        if(!$limitDecision['allowed']){
            return [
                'can_propose'=>false,
                'code'=>$limitDecision['code'],
                'reason'=>$limitDecision['reason'],
                'next_allowed_at'=>$limitDecision['next_allowed_at'],
                'pre_handoff_limits'=>$limitDecision,
            ];
        }
        $kernelActionType=match($channel){
            EngagementChannel::Email->value=>'growth.send_message',
            EngagementChannel::LinkedIn->value=>'growth.send_linkedin',
            EngagementChannel::Phone->value=>'growth.place_call',
            default=>'',
        };

        return [
            'can_propose'=>true,
            'code'=>'eligible_pre_handoff',
            'reason'=>'Accepted recommendation is eligible for governed pre-handoff '.$channel.' execution.',
            'target_domain'=>'growth',
            'target_reference_type'=>'growth_contact',
            'target_reference_id'=>$contactId,
            'action_type'=>$kernelActionType,
            'channel'=>$channel,
            'pre_handoff_limits'=>$limitDecision,
        ];
    }

    /** @return array{allowed:bool,code:string,reason:string,next_allowed_at:?string} */
    private function preHandoffLimitDecision(string $organizationId,string $contactId,string $channel):array
    {
        $now=$this->now();
        $today=$now->setTime(0,0);
        $since=$today->format('Y-m-d H:i:s.u');
        $count=$this->executions->countPreHandoffSince($organizationId,$since);
        $channelCount=$this->executions->countPreHandoffSinceByChannel($organizationId,$channel,$since);
        $latest=$this->executions->latestPreHandoffForTarget($organizationId,$contactId);
        $lastAt=null;
        if($latest!==null&&!empty($latest['created_at'])){
            try{$lastAt=new DateTimeImmutable((string)$latest['created_at'],new DateTimeZone('UTC'));}
            catch(\Throwable){throw new InvalidArgumentException('Stored Growth engagement execution timestamp is invalid.');}
        }
        return $this->limitProvider->policyFor($organizationId)->evaluate($count,$lastAt,$now,$channel,$channelCount);
    }

    private function assertExecutableRecommendation(string $actionType,string $channel):void
    {
        if(!in_array($actionType,self::EXECUTABLE_ACTIONS,true)){
            throw new InvalidArgumentException('Growth engagement recommendation is not executable through the current bridge.');
        }
        $action=NextBestActionType::tryFrom($actionType);
        $engagementChannel=EngagementChannel::tryFrom($channel);
        if(
            $action===null||
            $engagementChannel===null||
            !in_array($engagementChannel,[EngagementChannel::Email,EngagementChannel::LinkedIn,EngagementChannel::Phone],true)||
            !$action->allowsChannel($engagementChannel)
        ){
            throw new InvalidArgumentException('Growth engagement action/channel combination is not executable.');
        }
    }

    private function hasUsableChannelIdentity(string $organizationId,string $contactId,string $channel):bool
    {
        $contact=$this->contacts->viewContact($organizationId,$contactId);
        if($contact===null)return false;
        $identityType=strtolower(trim((string)($contact['identity_type']??'')));
        $identityValue=trim((string)($contact['identity_value']??''));
        if($identityType!==$channel)return false;

        return match($channel){
            EngagementChannel::Email->value=>filter_var($identityValue,FILTER_VALIDATE_EMAIL)!==false,
            EngagementChannel::Phone->value=>(bool)preg_match('/^\+[1-9][0-9]{7,14}$/',$identityValue),
            EngagementChannel::LinkedIn->value=>$this->isLinkedInProfile($identityValue),
            default=>false,
        };
    }

    private function isLinkedInProfile(string $value):bool
    {
        $parts=parse_url($value);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https')return false;
        $host=strtolower((string)($parts['host']??''));
        if($host!=='linkedin.com'&&!str_ends_with($host,'.linkedin.com'))return false;
        return (bool)preg_match('#^/in/[^/]+/?$#',(string)($parts['path']??''));
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
