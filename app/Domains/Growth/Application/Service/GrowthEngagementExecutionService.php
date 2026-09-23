<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthActionProposalGatewayInterface;
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
        if(count($deals)!==1){
            throw new InvalidArgumentException('Growth engagement execution requires exactly one bound sales_deal; found '.count($deals).'.');
        }
        $dealId=$deals[0];

        $payloadFingerprint=hash('sha256',json_encode([
            'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,'deal_id'=>$dealId,
            'action_type'=>'sales.send_message','channel'=>$channel,'body'=>$body,
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

        $kernelIdempotency='growth-engagement-'.substr(hash('sha256',$organizationId.':'.$recommendationId),0,40);
        $action=$this->actionGateway->proposeSalesMessage(
            $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$dealId,$channel,$body,
            isset($recommendation['confidence'])?(float)$recommendation['confidence']:null,$kernelIdempotency,
        );
        $executionId='GEXE-'.strtoupper(substr(hash('sha256',$organizationId.':'.$recommendationId),0,20));

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$dealId,$channel,
            $payloadFingerprint,$executionId,$action,$idempotencyKey
        ):array{
            $this->executions->createOrVerify(
                $organizationId,$executionId,$candidateId,$recommendationId,'sales','sales_deal',$dealId,
                $action->id,$action->type,$channel,$payloadFingerprint,$actorId,
            );
            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),$organizationId,GrowthEventType::ENGAGEMENT_EXECUTION_PROPOSED,
                'growth_candidate',$candidateId,[
                    'execution_id'=>$executionId,'recommendation_id'=>$recommendationId,
                    'action_id'=>$action->id,'action_type'=>$action->type,'action_status'=>$action->status,
                    'target_domain'=>'sales','target_reference_type'=>'sales_deal','target_reference_id'=>$dealId,
                    'channel'=>$channel,
                ],
                new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
            ));
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),$organizationId,'growth.engagement_execution','USER',(string)$actorId,
                'growth_candidate',$candidateId,null,[
                    'action'=>'growth.engagement.execution_proposed',
                    'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                    'input_references'=>['recommendation_id'=>$recommendationId,'sales_deal_id'=>$dealId],
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
        return ['recommendation'=>$recommendation,'execution'=>$execution,'action'=>$action];
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
