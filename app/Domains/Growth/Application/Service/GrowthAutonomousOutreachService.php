<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthAutonomousOutreachBoundary;
use Domains\Growth\Application\Contract\GrowthAutonomousOutreachRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementActivationProviderInterface;
use Domains\Growth\Application\Contract\GrowthEngagementBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\AutonomousOutreachEligibilityPolicy;
use Domains\Growth\Domain\AutonomousOutreachDeferred;
use Domains\Growth\Domain\EngagementActivationMode;
use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\EngagementRecommendationStatus;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthAutonomousOutreachService implements GrowthAutonomousOutreachBoundary
{
    public function __construct(
        private GrowthAutonomousOutreachRepositoryInterface $repository,
        private GrowthEngagementRepositoryInterface $engagementRepository,
        private GrowthEngagementBoundary $engagement,
        private GrowthEngagementExecutionBoundary $execution,
        private GrowthEngagementExecutionRepositoryInterface $executionRepository,
        private GrowthEngagementActivationProviderInterface $activation,
        private GrowthMutationReceiptInterface $receipts,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function viewPolicy(string $organizationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $profile=$this->repository->latestProfile($organizationId);
        $policy=$this->policyFor($organizationId);
        return [
            'source'=>$profile===null?'safe_default':'tenant_profile',
            'profile'=>$profile,
            'effective'=>$policy->toArray(),
            'defaults'=>$this->safeDefault()->toArray(),
        ];
    }

    public function updatePolicy(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $enabled=$this->boolean($input['enabled']??false,'enabled');
        $minConfidence=$this->number($input['min_confidence']??null,'min_confidence');
        $allowedChannels=$this->stringList($input['allowed_channels']??null,'allowed_channels');
        $allowedStatuses=$this->stringList($input['allowed_statuses']??null,'allowed_statuses');
        $maxActions=$this->integer($input['max_actions_per_run']??null,'max_actions_per_run');
        $reason=$this->bounded((string)($input['reason']??''),'reason',1000);
        $policy=new AutonomousOutreachEligibilityPolicy($enabled,$minConfidence,$allowedChannels,$allowedStatuses,$maxActions);

        $fingerprint=hash('sha256',json_encode($policy->toArray()+['reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$reason,$policy,$fingerprint
        ):array{
            $this->executionRepository->lockPreHandoffCapacity($organizationId);
            if(!$this->receipts->claim($organizationId,'engagement_autonomy_profile_update',$idempotencyKey,$fingerprint)){
                return $this->viewPolicy($organizationId)+['replayed'=>true];
            }

            $latest=$this->repository->latestProfile($organizationId);
            $current=$latest===null?null:$this->policyFromProfile($latest);
            if($current!==null&&$current->toArray()===$policy->toArray()){
                throw new InvalidArgumentException('Growth autonomous outreach policy is unchanged.');
            }

            $revision=(int)($latest['revision']??0)+1;
            $profileId='GAOP-'.strtoupper(substr(hash('sha256',$organizationId.':'.$revision),0,20));
            $profile=[
                'organization_id'=>$organizationId,'profile_id'=>$profileId,'revision'=>$revision,
                ...$policy->toArray(),'reason'=>$reason,'created_by'=>$actorId,'created_at'=>$this->now()->format(DATE_ATOM),
            ];
            $this->repository->appendProfile($profile);
            $this->publish(
                GrowthEventType::ENGAGEMENT_AUTONOMY_PROFILE_UPDATED,$organizationId,'growth_engagement_autonomy_profile',$profileId,
                ['revision'=>$revision,'policy'=>$policy->toArray()],$correlationId,'USER',(string)$actorId,
            );
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),$organizationId,'growth.engagement_autonomy','USER',(string)$actorId,
                'growth_engagement_autonomy_profile',$profileId,null,[
                    'action'=>'growth.engagement_autonomy.updated',
                    'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                    'result'=>['revision'=>$revision,'policy'=>$policy->toArray(),'reason'=>$reason],
                ],$correlationId,$this->now(),
            ));
            return $this->viewPolicy($organizationId);
        });
    }

    public function stagePayload(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $body,string $reason,string $idempotencyKey,string $actorType='USER'
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $actorType=strtoupper(trim($actorType));
        if(!in_array($actorType,['USER','SYSTEM'],true))throw new InvalidArgumentException('Growth autonomous payload actor type is invalid.');
        $candidateId=$this->bounded($candidateId,'candidateId',80);
        $recommendationId=$this->bounded($recommendationId,'recommendationId',80);
        $body=$this->bounded($body,'body',10000);
        $reason=$this->bounded($reason,'reason',1000);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $payloadFingerprint=hash('sha256',$body);
        $mutationFingerprint=hash('sha256',json_encode([
            'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,'body'=>$body,'reason'=>$reason,'actor_type'=>$actorType,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$body,$reason,$idempotencyKey,$actorType,
            $payloadFingerprint,$mutationFingerprint
        ):array{
            $this->executionRepository->lockPreHandoffCapacity($organizationId);
            $recommendation=$this->recommendation($organizationId,$candidateId,$recommendationId);
            $this->assertStageableRecommendation($recommendation);

            if($this->executionRepository->byRecommendation($organizationId,$recommendationId)!==null){
                throw new InvalidArgumentException('Cannot stage autonomous payload after execution was already proposed.');
            }

            if(!$this->receipts->claim($organizationId,'engagement_autonomy_payload_stage',$idempotencyKey,$mutationFingerprint)){
                return $this->recommendationBrief($organizationId,$candidateId,$recommendationId)+['replayed'=>true];
            }

            $latest=$this->repository->latestPayload($organizationId,$recommendationId);
            if($latest!==null&&hash_equals((string)$latest['payload_fingerprint'],$payloadFingerprint)){
                throw new InvalidArgumentException('Autonomous outreach payload is unchanged.');
            }
            $revision=(int)($latest['revision']??0)+1;
            $payloadId='GAOPL-'.strtoupper(substr(hash('sha256',$organizationId.':'.$recommendationId.':'.$revision),0,20));
            $payload=[
                'organization_id'=>$organizationId,'payload_id'=>$payloadId,'recommendation_id'=>$recommendationId,
                'candidate_id'=>$candidateId,'revision'=>$revision,'body'=>$body,'payload_fingerprint'=>$payloadFingerprint,
                'reason'=>$reason,'staged_by'=>$actorId,'staged_at'=>$this->now()->format(DATE_ATOM),
            ];
            $this->repository->appendPayload($payload);
            $this->publish(
                GrowthEventType::ENGAGEMENT_AUTONOMY_PAYLOAD_STAGED,$organizationId,'growth_candidate',$candidateId,
                ['payload_id'=>$payloadId,'recommendation_id'=>$recommendationId,'revision'=>$revision,'payload_fingerprint'=>$payloadFingerprint],
                $correlationId,$actorType,(string)$actorId,
            );
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),$organizationId,'growth.engagement_autonomy',$actorType,(string)$actorId,
                'growth_candidate',$candidateId,null,[
                    'action'=>'growth.engagement_autonomy.payload_staged',
                    'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                    'input_references'=>['recommendation_id'=>$recommendationId],
                    'result'=>['payload_id'=>$payloadId,'revision'=>$revision,'payload_fingerprint'=>$payloadFingerprint,'reason'=>$reason],
                ],$correlationId,$this->now(),
            ));
            return $this->recommendationBrief($organizationId,$candidateId,$recommendationId);
        });
    }

    public function recommendationBrief(string $organizationId,string $candidateId,string $recommendationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $candidateId=$this->bounded($candidateId,'candidateId',80);
        $recommendationId=$this->bounded($recommendationId,'recommendationId',80);
        $recommendation=$this->recommendation($organizationId,$candidateId,$recommendationId);
        $payload=$this->repository->latestPayload($organizationId,$recommendationId);
        $activation=$this->activationForRecommendation($recommendation);
        $policy=$this->policyFor($organizationId);
        $publicPayload=$payload;
        if(is_array($publicPayload))unset($publicPayload['body']);
        return [
            'policy'=>$policy->toArray(),
            'payload'=>$publicPayload,
            'activation_mode'=>$activation->value,
            'eligibility'=>$policy->evaluate($recommendation,$payload!==null,$activation),
        ];
    }

    public function triggerRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,string $triggerKey
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $candidateId=$this->bounded($candidateId,'candidateId',80);
        $recommendationId=$this->bounded($recommendationId,'recommendationId',80);
        $triggerKey=$this->bounded($triggerKey,'triggerKey',191);

        try{
            return $this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$triggerKey
            ):array{
                $this->executionRepository->lockPreHandoffCapacity($organizationId);
                if($this->executionRepository->byRecommendation($organizationId,$recommendationId)!==null){
                    return ['status'=>'replayed','code'=>'already_executed','recommendation_id'=>$recommendationId];
                }

                $recommendation=$this->recommendation($organizationId,$candidateId,$recommendationId);
                $payload=$this->repository->latestPayload($organizationId,$recommendationId);
                $policy=$this->policyFor($organizationId);
                $activation=$this->activationForRecommendation($recommendation);
                $decision=$policy->evaluate($recommendation,$payload!==null,$activation);
                if(!$decision['allowed']){
                    return ['status'=>'skipped','recommendation_id'=>$recommendationId,'eligibility'=>$decision];
                }
                if($payload===null)throw new InvalidArgumentException('Eligible autonomous outreach payload disappeared during admission.');

                if((string)$recommendation['status']===EngagementRecommendationStatus::Proposed->value){
                    $this->engagement->acceptRecommendation(
                        $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,
                        'Accepted by tenant autonomous outreach policy.',
                        'autonomy-accept-'.$recommendationId,
                    );
                }

                $executionBrief=$this->execution->executionBrief($organizationId,$candidateId,$recommendationId);
                $executionEligibility=$executionBrief['eligibility']??null;
                if(!is_array($executionEligibility)||empty($executionEligibility['can_propose'])){
                    $eligibility=is_array($executionEligibility)?$executionEligibility:[
                        'code'=>'execution_not_eligible','reason'=>'Recommendation is not eligible for governed execution.',
                    ];
                    throw new AutonomousOutreachDeferred(
                        (string)($eligibility['code']??'execution_not_eligible'),
                        (string)($eligibility['reason']??'Recommendation is not eligible for governed execution.'),
                        $eligibility,
                    );
                }
                if((string)($executionEligibility['target_domain']??'')!=='growth'){
                    throw new AutonomousOutreachDeferred(
                        'autonomy_pre_handoff_only',
                        'Growth autonomous outreach does not execute post-handoff Sales actions.',
                        $executionEligibility,
                    );
                }
                if((string)($executionEligibility['activation_mode']??'')!==EngagementActivationMode::Auto->value){
                    throw new AutonomousOutreachDeferred(
                        'channel_not_auto',
                        'Autonomous initiation requires channel activation mode AUTO.',
                        $executionEligibility,
                    );
                }

                $result=$this->execution->proposeMessageAction(
                    $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,(string)$payload['body'],
                    'autonomy-execution-'.$recommendationId,
                );
                $action=is_array($result['action']??null)?$result['action']:[];
                $this->publish(
                    GrowthEventType::ENGAGEMENT_AUTONOMOUS_TRIGGERED,$organizationId,'growth_candidate',$candidateId,
                    ['recommendation_id'=>$recommendationId,'payload_id'=>$payload['payload_id']??null,'action_id'=>$action['action_id']??null,'trigger_key'=>$triggerKey],
                    $correlationId,'SYSTEM',(string)$actorId,
                );
                $this->audit->append(new AuditEntry(
                    bin2hex(random_bytes(16)),$organizationId,'growth.autonomous_outreach','SYSTEM',(string)$actorId,
                    'growth_candidate',$candidateId,null,[
                        'action'=>'growth.engagement.autonomous_triggered',
                        'input_references'=>['recommendation_id'=>$recommendationId,'payload_id'=>$payload['payload_id']??null],
                        'result'=>['action_id'=>$action['action_id']??null,'action_status'=>$action['status']??null,'trigger_key_hash'=>hash('sha256',$triggerKey)],
                    ],$correlationId,$this->now(),
                ));
                return ['status'=>'triggered','recommendation_id'=>$recommendationId,'execution'=>$result['execution']??null,'action'=>$action];
            });
        }catch(AutonomousOutreachDeferred $deferred){
            return [
                'status'=>'skipped',
                'recommendation_id'=>$recommendationId,
                'eligibility'=>$deferred->eligibility,
            ];
        }
    }

    private function policyFor(string $organizationId):AutonomousOutreachEligibilityPolicy
    {
        $profile=$this->repository->latestProfile($organizationId);
        return $profile===null?$this->safeDefault():$this->policyFromProfile($profile);
    }

    /** @param array<string,mixed> $profile */
    private function policyFromProfile(array $profile):AutonomousOutreachEligibilityPolicy
    {
        return new AutonomousOutreachEligibilityPolicy(
            (bool)$profile['enabled'],(float)$profile['min_confidence'],
            array_values(array_map('strval',$profile['allowed_channels']??[])),
            array_values(array_map('strval',$profile['allowed_statuses']??[])),
            (int)$profile['max_actions_per_run'],
        );
    }

    private function safeDefault():AutonomousOutreachEligibilityPolicy
    {
        return new AutonomousOutreachEligibilityPolicy(false,0.90,['email'],['accepted'],5);
    }

    /** @return array<string,mixed> */
    private function recommendation(string $organizationId,string $candidateId,string $recommendationId):array
    {
        $recommendation=$this->engagementRepository->viewRecommendation($organizationId,$recommendationId)
            ?? throw new InvalidArgumentException('Growth engagement recommendation was not found.');
        if((string)($recommendation['candidate_id']??'')!==$candidateId){
            throw new InvalidArgumentException('Growth engagement recommendation belongs to another Candidate.');
        }
        return $recommendation;
    }

    /** @param array<string,mixed> $recommendation */
    private function activationForRecommendation(array $recommendation):EngagementActivationMode
    {
        $channel=EngagementChannel::tryFrom(strtolower(trim((string)($recommendation['channel']??''))));
        if($channel===null||!in_array($channel,[EngagementChannel::Email,EngagementChannel::LinkedIn,EngagementChannel::Phone],true)){
            return EngagementActivationMode::Blocked;
        }
        return $this->activation->modeFor((string)$recommendation['organization_id'],$channel->value);
    }

    /** @param array<string,mixed> $recommendation */
    private function assertStageableRecommendation(array $recommendation):void
    {
        $status=(string)($recommendation['status']??'');
        if(!in_array($status,[EngagementRecommendationStatus::Proposed->value,EngagementRecommendationStatus::Accepted->value],true)){
            throw new InvalidArgumentException('Only proposed or accepted recommendations can stage autonomous payloads.');
        }
        $channel=EngagementChannel::tryFrom(strtolower(trim((string)($recommendation['channel']??''))));
        if($channel===null||!in_array($channel,[EngagementChannel::Email,EngagementChannel::LinkedIn,EngagementChannel::Phone],true)){
            throw new InvalidArgumentException('Only direct outreach recommendations can stage autonomous payloads.');
        }
    }

    private function boolean(mixed $value,string $field):bool
    {
        if(is_bool($value))return $value;
        if($value===0||$value===1||$value==='0'||$value==='1')return (bool)$value;
        throw new InvalidArgumentException($field.' must be boolean.');
    }

    private function number(mixed $value,string $field):float
    {
        if(is_int($value)||is_float($value))return (float)$value;
        if(is_string($value)&&is_numeric(trim($value)))return (float)trim($value);
        throw new InvalidArgumentException($field.' must be numeric.');
    }

    private function integer(mixed $value,string $field):int
    {
        if(is_int($value))return $value;
        if(is_string($value)&&ctype_digit(trim($value)))return (int)trim($value);
        throw new InvalidArgumentException($field.' must be an integer.');
    }

    /** @return list<string> */
    private function stringList(mixed $value,string $field):array
    {
        if(!is_array($value)||!array_is_list($value)||$value===[])throw new InvalidArgumentException($field.' must be a non-empty list.');
        return array_values(array_map(static function(mixed $item)use($field):string{
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException($field.' contains an invalid value.');
            return strtolower(trim($item));
        },$value));
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,
        string $correlationId,string $actorType,string $actorId
    ):void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,$actorType,$actorId),$this->now(),
        ));
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
