<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthEngagementDeliveryRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceBoundary;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceGuardInterface;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceRepositoryInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\EngagementRecommendation;
use Domains\Growth\Domain\EngagementRecommendationStatus;
use Domains\Growth\Domain\NextBestActionType;
use Domains\Growth\Domain\OutreachSequencePolicy;
use Domains\Growth\Domain\OutreachSequenceStateMachine;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthOutreachSequenceService implements GrowthOutreachSequenceBoundary
{
    public function __construct(
        private GrowthOutreachSequenceRepositoryInterface $repository,
        private GrowthOutreachSequenceGuardInterface $guard,
        private GrowthEngagementRepositoryInterface $engagement,
        private GrowthEngagementExecutionRepositoryInterface $executions,
        private GrowthEngagementDeliveryRepositoryInterface $deliveries,
        private GrowthMutationReceiptInterface $receipts,
        private OutreachSequenceStateMachine $stateMachine,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function viewPolicy(string $organizationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $profile=$this->repository->latestProfile($organizationId);
        $policy=$profile===null?$this->safeDefault():$this->policyFromProfile($profile);
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
        $reason=$this->bounded((string)($input['reason']??''),'reason',1000);
        $policy=new OutreachSequencePolicy(
            $this->boolean($input['enabled']??false,'enabled'),
            $this->stringList($input['allowed_channels']??null,'allowed_channels'),
            $this->integer($input['max_touches']??null,'max_touches'),
            $this->integer($input['follow_up_delay_hours']??null,'follow_up_delay_hours'),
            $this->integer($input['max_advances_per_run']??null,'max_advances_per_run'),
        );
        $fingerprint=hash('sha256',json_encode($policy->toArray()+['reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$reason,$policy,$fingerprint
        ):array{
            $this->executions->lockPreHandoffCapacity($organizationId);
            if(!$this->receipts->claim($organizationId,'engagement_sequence_policy_update',$idempotencyKey,$fingerprint)){
                return $this->viewPolicy($organizationId)+['replayed'=>true];
            }

            $latest=$this->repository->latestProfile($organizationId);
            $current=$latest===null?null:$this->policyFromProfile($latest);
            if($current!==null&&$current->toArray()===$policy->toArray()){
                throw new InvalidArgumentException('Growth outreach sequence policy is unchanged.');
            }

            $activationStartedAt=null;
            if($policy->enabled){
                $activationStartedAt=!empty($latest['enabled'])&&!empty($latest['activation_started_at'])
                    ?(string)$latest['activation_started_at']
                    :$this->now()->format(DATE_ATOM);
            }

            $revision=(int)($latest['revision']??0)+1;
            $profileId='GSQP-'.strtoupper(substr(hash('sha256',$organizationId.':'.$revision),0,20));
            $profile=[
                'organization_id'=>$organizationId,'profile_id'=>$profileId,'revision'=>$revision,
                ...$policy->toArray(),'activation_started_at'=>$activationStartedAt,'reason'=>$reason,
                'created_by'=>$actorId,'created_at'=>$this->now()->format(DATE_ATOM),
            ];
            $this->repository->appendProfile($profile);
            $this->publish(
                GrowthEventType::ENGAGEMENT_SEQUENCE_PROFILE_UPDATED,$organizationId,'growth_engagement_sequence_profile',$profileId,
                ['revision'=>$revision,'policy'=>$policy->toArray(),'activation_started_at'=>$activationStartedAt],
                $correlationId,'USER',(string)$actorId,
            );
            $this->appendAudit($organizationId,'USER',(string)$actorId,'growth_engagement_sequence_profile',$profileId,$correlationId,[
                'action'=>'growth.engagement.sequence_policy_updated',
                'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                'result'=>['revision'=>$revision,'policy'=>$policy->toArray(),'activation_started_at'=>$activationStartedAt,'reason'=>$reason],
            ]);
            return $this->viewPolicy($organizationId);
        });
    }

    public function sequenceBrief(string $organizationId,string $candidateId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $candidateId=$this->bounded($candidateId,'candidateId',80);
        $sequence=$this->repository->latestForCandidate($organizationId,$candidateId);
        $steps=[];
        if($sequence!==null){
            foreach($this->repository->steps($organizationId,(string)$sequence['sequence_id']) as $step){
                $execution=$this->executions->byRecommendation($organizationId,(string)$step['recommendation_id']);
                $delivery=$execution===null?null:$this->deliveries->latestForExecution($organizationId,(string)$execution['execution_id']);
                $steps[]=$step+['execution'=>$execution,'latest_delivery'=>$delivery];
            }
        }
        return ['policy'=>$this->viewPolicy($organizationId),'sequence'=>$sequence,'steps'=>$steps];
    }

    public function stopSequence(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $sequenceId,
        string $reason,string $idempotencyKey
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $candidateId=$this->bounded($candidateId,'candidateId',80);
        $sequenceId=$this->bounded($sequenceId,'sequenceId',80);
        $reason=$this->bounded($reason,'reason',1000);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $fingerprint=hash('sha256',json_encode([
            'candidate_id'=>$candidateId,'sequence_id'=>$sequenceId,'reason'=>$reason,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$sequenceId,$reason,$idempotencyKey,$fingerprint
        ):array{
            $this->executions->lockPreHandoffCapacity($organizationId);
            if(!$this->receipts->claim($organizationId,'engagement_sequence_stop',$idempotencyKey,$fingerprint)){
                return $this->sequenceBrief($organizationId,$candidateId)+['replayed'=>true];
            }
            $sequence=$this->repository->lockSequence($organizationId,$sequenceId);
            if((string)$sequence['candidate_id']!==$candidateId){
                throw new InvalidArgumentException('Growth outreach sequence belongs to another Candidate.');
            }
            if((string)$sequence['status']==='active'){
                $this->repository->finish($organizationId,$sequenceId,'stopped','operator_stop',$reason);
                $this->publish(
                    GrowthEventType::ENGAGEMENT_SEQUENCE_STOPPED,$organizationId,'growth_candidate',$candidateId,
                    ['sequence_id'=>$sequenceId,'code'=>'operator_stop','reason'=>$reason],
                    $correlationId,'USER',(string)$actorId,
                );
                $this->appendAudit($organizationId,'USER',(string)$actorId,'growth_candidate',$candidateId,$correlationId,[
                    'action'=>'growth.engagement.sequence_stopped',
                    'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                    'result'=>['sequence_id'=>$sequenceId,'code'=>'operator_stop','reason'=>$reason],
                ]);
            }
            return $this->sequenceBrief($organizationId,$candidateId);
        });
    }

    public function runOrganization(string $organizationId,int $actorId,string $correlationId,string $triggerKey):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $triggerKey=$this->bounded($triggerKey,'triggerKey',191);
        $policy=$this->effectivePolicy($organizationId);
        $stats=['started'=>0,'advanced'=>0,'stopped'=>0,'completed'=>0,'waiting'=>0];
        $details=[];

        if($policy->enabled){
            foreach($this->repository->initialCandidates($organizationId,$policy->maxAdvancesPerRun) as $row){
                $result=$this->bootstrap($organizationId,$actorId,$correlationId,$row);
                if(($result['status']??'')==='started')$stats['started']++;
                $details[]=$result;
            }
        }

        foreach($this->repository->activeSequences($organizationId,$policy->maxAdvancesPerRun) as $sequence){
            $result=$this->reconcile($organizationId,$actorId,$correlationId,$sequence);
            match((string)($result['action']??'wait')){
                'advance'=>$stats['advanced']++,
                'stop'=>$stats['stopped']++,
                'complete'=>$stats['completed']++,
                default=>$stats['waiting']++,
            };
            $details[]=$result;
        }

        return ['status'=>$policy->enabled?'completed':'drained_disabled','trigger_key_hash'=>hash('sha256',$triggerKey)]+$stats+['details'=>$details];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function bootstrap(string $organizationId,int $actorId,string $correlationId,array $row):array
    {
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$row):array{
            $this->executions->lockPreHandoffCapacity($organizationId);
            $rootRecommendationId=$this->bounded((string)($row['recommendation_id']??''),'recommendationId',80);

            $existing=$this->repository->sequenceByRootRecommendation($organizationId,$rootRecommendationId);
            if($existing!==null)return ['status'=>'existing','sequence_id'=>$existing['sequence_id']];

            $recommendation=$this->engagement->viewRecommendation($organizationId,$rootRecommendationId)
                ??throw new InvalidArgumentException('Growth sequence root recommendation was not found.');
            $execution=$this->executions->byRecommendation($organizationId,$rootRecommendationId)
                ??throw new InvalidArgumentException('Growth sequence root execution was not found.');

            $profile=$this->repository->latestProfile($organizationId);
            $policy=$profile===null?$this->safeDefault():$this->policyFromProfile($profile);
            if(!$policy->enabled||!$policy->allowsChannel((string)$recommendation['channel'])){
                return ['status'=>'ineligible','code'=>'sequence_policy_ineligible'];
            }
            if(empty($profile['activation_started_at'])){
                return ['status'=>'ineligible','code'=>'sequence_activation_window_missing'];
            }

            $executionAt=new DateTimeImmutable((string)$execution['created_at'],new DateTimeZone('UTC'));
            if($executionAt<new DateTimeImmutable((string)$profile['activation_started_at'],new DateTimeZone('UTC'))){
                return ['status'=>'before_enablement_window'];
            }

            $block=$this->guard->bootstrapBlock($organizationId,$recommendation,$executionAt);
            if($block!==null)return ['status'=>'stop_signal','code'=>$block['code'],'reason'=>$block['reason']];

            $sequenceId='GSQ-'.strtoupper(substr(hash('sha256',$organizationId.':'.$rootRecommendationId),0,20));
            $startedAt=$executionAt->format(DATE_ATOM);
            $this->repository->createSequence([
                'organization_id'=>$organizationId,'sequence_id'=>$sequenceId,
                'candidate_id'=>$recommendation['candidate_id'],'root_recommendation_id'=>$rootRecommendationId,
                'contact_id'=>$recommendation['contact_id'],'channel'=>$recommendation['channel'],
                'max_touches'=>$policy->maxTouches,'last_recommendation_id'=>$rootRecommendationId,
                'started_by'=>$actorId,'started_at'=>$startedAt,'updated_at'=>$this->now()->format(DATE_ATOM),
            ]);
            $this->repository->appendStep([
                'organization_id'=>$organizationId,'step_id'=>$this->stepId($sequenceId,1),
                'sequence_id'=>$sequenceId,'touch_number'=>1,'recommendation_id'=>$rootRecommendationId,
                'parent_recommendation_id'=>null,'created_by'=>$actorId,'created_at'=>$startedAt,
            ]);

            $this->publish(
                GrowthEventType::ENGAGEMENT_SEQUENCE_STARTED,$organizationId,'growth_candidate',(string)$recommendation['candidate_id'],
                ['sequence_id'=>$sequenceId,'root_recommendation_id'=>$rootRecommendationId,'channel'=>$recommendation['channel'],'max_touches'=>$policy->maxTouches],
                $correlationId,'SYSTEM',(string)$actorId,
            );
            $this->appendAudit($organizationId,'SYSTEM',(string)$actorId,'growth_candidate',(string)$recommendation['candidate_id'],$correlationId,[
                'action'=>'growth.engagement.sequence_started',
                'result'=>['sequence_id'=>$sequenceId,'root_recommendation_id'=>$rootRecommendationId,'max_touches'=>$policy->maxTouches],
            ]);

            return ['status'=>'started','sequence_id'=>$sequenceId];
        });
    }

    /** @param array<string,mixed> $inputSequence @return array<string,mixed> */
    private function reconcile(string $organizationId,int $actorId,string $correlationId,array $inputSequence):array
    {
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$inputSequence):array{
            $this->executions->lockPreHandoffCapacity($organizationId);
            $sequence=$this->repository->lockSequence($organizationId,(string)$inputSequence['sequence_id']);
            if((string)$sequence['status']!=='active'){
                return ['action'=>'wait','code'=>'sequence_not_active','sequence_id'=>$sequence['sequence_id']];
            }

            $policy=$this->effectivePolicy($organizationId);
            $latestStep=$this->repository->latestStep($organizationId,(string)$sequence['sequence_id'])
                ??throw new InvalidArgumentException('Growth outreach sequence has no steps.');
            $recommendation=$this->engagement->viewRecommendation($organizationId,(string)$latestStep['recommendation_id'])
                ??throw new InvalidArgumentException('Growth sequence recommendation disappeared.');

            $block=$this->guard->blockingForRecommendation($organizationId,$recommendation);
            $execution=$this->executions->byRecommendation($organizationId,(string)$latestStep['recommendation_id']);
            $delivery=$execution===null?null:$this->deliveries->latestForExecution($organizationId,(string)$execution['execution_id']);

            $anchorAt=null;
            if(is_array($delivery)&&!empty($delivery['occurred_at'])){
                $anchorAt=new DateTimeImmutable((string)$delivery['occurred_at']);
            }elseif(is_array($execution)&&!empty($execution['created_at'])){
                $anchorAt=new DateTimeImmutable((string)$execution['created_at']);
            }

            $decision=$this->stateMachine->evaluate(
                $policy,
                (string)$sequence['channel'],
                (int)$sequence['touch_count'],
                $execution!==null,
                is_array($delivery)?(string)($delivery['status']??''):null,
                $anchorAt,
                $this->now(),
                $block['code']??null,
            );

            if(($decision['action']??null)==='stop'){
                $reason=$block['reason']??(string)$decision['reason'];
                $this->repository->finish($organizationId,(string)$sequence['sequence_id'],'stopped',(string)$decision['code'],$reason);
                $this->publish(
                    GrowthEventType::ENGAGEMENT_SEQUENCE_STOPPED,$organizationId,'growth_candidate',(string)$sequence['candidate_id'],
                    ['sequence_id'=>$sequence['sequence_id'],'code'=>$decision['code'],'reason'=>$reason],
                    $correlationId,'SYSTEM',(string)$actorId,
                );
                $this->appendAudit($organizationId,'SYSTEM',(string)$actorId,'growth_candidate',(string)$sequence['candidate_id'],$correlationId,[
                    'action'=>'growth.engagement.sequence_stopped',
                    'result'=>['sequence_id'=>$sequence['sequence_id'],'code'=>$decision['code'],'reason'=>$reason],
                ]);
                return ['action'=>'stop','sequence_id'=>$sequence['sequence_id'],'code'=>$decision['code']];
            }

            if(($decision['action']??null)==='complete'){
                $this->repository->finish(
                    $organizationId,(string)$sequence['sequence_id'],'completed',(string)$decision['code'],(string)$decision['reason']
                );
                $this->publish(
                    GrowthEventType::ENGAGEMENT_SEQUENCE_COMPLETED,$organizationId,'growth_candidate',(string)$sequence['candidate_id'],
                    ['sequence_id'=>$sequence['sequence_id'],'code'=>$decision['code'],'touch_count'=>$sequence['touch_count']],
                    $correlationId,'SYSTEM',(string)$actorId,
                );
                $this->appendAudit($organizationId,'SYSTEM',(string)$actorId,'growth_candidate',(string)$sequence['candidate_id'],$correlationId,[
                    'action'=>'growth.engagement.sequence_completed',
                    'result'=>['sequence_id'=>$sequence['sequence_id'],'touch_count'=>$sequence['touch_count']],
                ]);
                return ['action'=>'complete','sequence_id'=>$sequence['sequence_id'],'code'=>$decision['code']];
            }

            if(($decision['action']??null)==='wait'){
                if(is_string($decision['next_due_at']??null)&&$decision['next_due_at']!==''
                    &&(string)($sequence['next_due_at']??'')!==(string)$decision['next_due_at']){
                    $this->repository->scheduleDue($organizationId,(string)$sequence['sequence_id'],(string)$decision['next_due_at']);
                    $this->publish(
                        GrowthEventType::ENGAGEMENT_SEQUENCE_DUE_SCHEDULED,$organizationId,'growth_candidate',(string)$sequence['candidate_id'],
                        ['sequence_id'=>$sequence['sequence_id'],'touch_count'=>$sequence['touch_count'],'next_due_at'=>$decision['next_due_at']],
                        $correlationId,'SYSTEM',(string)$actorId,
                    );
                }
                return [
                    'action'=>'wait','sequence_id'=>$sequence['sequence_id'],'code'=>$decision['code'],
                    'next_due_at'=>$decision['next_due_at']??null,
                ];
            }

            return $this->advanceSequence($organizationId,$actorId,$correlationId,$sequence,$latestStep);
        });
    }

    /** @param array<string,mixed> $sequence @param array<string,mixed> $latestStep @return array<string,mixed> */
    private function advanceSequence(string $organizationId,int $actorId,string $correlationId,array $sequence,array $latestStep):array
    {
        $root=$this->engagement->viewRecommendation($organizationId,(string)$sequence['root_recommendation_id'])
            ??throw new InvalidArgumentException('Growth sequence root recommendation disappeared.');
        $nextTouch=(int)$sequence['touch_count']+1;
        $recommendationId='GSQR-'.strtoupper(substr(hash('sha256',$organizationId.':'.$sequence['sequence_id'].':'.$nextTouch),0,20));
        $runId='GSQN-'.strtoupper(substr(hash('sha256',$organizationId.':'.$recommendationId),0,20));

        if($this->engagement->viewRecommendation($organizationId,$recommendationId)===null){
            $this->engagement->createRun(
                $organizationId,$runId,(string)$sequence['candidate_id'],
                [
                    'sequence_id'=>$sequence['sequence_id'],'root_recommendation_id'=>$sequence['root_recommendation_id'],
                    'parent_recommendation_id'=>$latestStep['recommendation_id'],'touch_number'=>$nextTouch,'reason'=>'follow_up_due',
                ],
                'growth-sequence-v1','growth-sequence-schema-v1',$actorId,
            );
            $this->publish(
                GrowthEventType::ENGAGEMENT_RUN_STARTED,$organizationId,'growth_candidate',(string)$sequence['candidate_id'],
                ['run_id'=>$runId,'prompt_version'=>'growth-sequence-v1','schema_version'=>'growth-sequence-schema-v1','sequence_id'=>$sequence['sequence_id']],
                $correlationId,'SYSTEM',(string)$actorId,
            );

            $actionType=NextBestActionType::tryFrom((string)$root['action_type'])
                ??throw new InvalidArgumentException('Growth sequence root action type is invalid.');
            $channel=EngagementChannel::tryFrom((string)$root['channel'])
                ??throw new InvalidArgumentException('Growth sequence root channel is invalid.');
            $unknowns=array_values(array_unique([...array_map('strval',$root['unknowns']??[]),'reply_status_unknown']));
            $derived=new EngagementRecommendation(
                $recommendationId,
                OrganizationId::fromString($organizationId),
                (string)$sequence['candidate_id'],
                $actionType,
                $channel,
                $root['contact_id']===null?null:(string)$root['contact_id'],
                'Sequence follow-up became due without an authoritative stop signal.',
                'Follow-up touch '.$nextTouch.'. Do not claim the prior message was read or remembered. Continue this angle: '.(string)$root['message_angle'],
                array_values(array_map('strval',$root['evidence_ids']??[])),
                $unknowns,
                (float)$root['confidence'],
                'growth-sequence',
                'deterministic-follow-up',
                'growth-sequence-v1',
                'growth-sequence-schema-v1',
                $this->now(),
                EngagementRecommendationStatus::Accepted,
                'Accepted by active tenant outreach sequence policy.',
            );
            $this->engagement->createRecommendation($derived,$runId,$actorId);
            $this->engagement->completeRun(
                $organizationId,$runId,$recommendationId,'growth-sequence','deterministic-follow-up',null,null,null,null
            );
            $this->publish(
                GrowthEventType::ENGAGEMENT_RUN_COMPLETED,$organizationId,'growth_candidate',(string)$sequence['candidate_id'],
                ['run_id'=>$runId,'recommendation_id'=>$recommendationId,'action_type'=>$actionType->value,'channel'=>$channel->value,'sequence_id'=>$sequence['sequence_id']],
                $correlationId,'SYSTEM',(string)$actorId,
            );
            $this->publish(
                GrowthEventType::ENGAGEMENT_RECOMMENDATION_CREATED,$organizationId,'growth_candidate',(string)$sequence['candidate_id'],
                [
                    'recommendation_id'=>$recommendationId,'action_type'=>$actionType->value,'channel'=>$channel->value,
                    'contact_id'=>$root['contact_id'],'confidence'=>$root['confidence'],'sequence_id'=>$sequence['sequence_id'],'touch_number'=>$nextTouch,
                ],
                $correlationId,'SYSTEM',(string)$actorId,
            );
            $this->publish(
                GrowthEventType::ENGAGEMENT_RECOMMENDATION_ACCEPTED,$organizationId,'growth_candidate',(string)$sequence['candidate_id'],
                [
                    'recommendation_id'=>$recommendationId,'reason'=>'Accepted by active tenant outreach sequence policy.',
                    'sequence_id'=>$sequence['sequence_id'],'touch_number'=>$nextTouch,
                ],
                $correlationId,'SYSTEM',(string)$actorId,
            );
        }

        $this->repository->appendStep([
            'organization_id'=>$organizationId,'step_id'=>$this->stepId((string)$sequence['sequence_id'],$nextTouch),
            'sequence_id'=>$sequence['sequence_id'],'touch_number'=>$nextTouch,'recommendation_id'=>$recommendationId,
            'parent_recommendation_id'=>$latestStep['recommendation_id'],'created_by'=>$actorId,'created_at'=>$this->now()->format(DATE_ATOM),
        ]);
        $this->repository->advance($organizationId,(string)$sequence['sequence_id'],$nextTouch,$recommendationId);
        $this->publish(
            GrowthEventType::ENGAGEMENT_SEQUENCE_ADVANCED,$organizationId,'growth_candidate',(string)$sequence['candidate_id'],
            [
                'sequence_id'=>$sequence['sequence_id'],'touch_number'=>$nextTouch,'recommendation_id'=>$recommendationId,
                'parent_recommendation_id'=>$latestStep['recommendation_id'],
            ],
            $correlationId,'SYSTEM',(string)$actorId,
        );
        $this->appendAudit($organizationId,'SYSTEM',(string)$actorId,'growth_candidate',(string)$sequence['candidate_id'],$correlationId,[
            'action'=>'growth.engagement.sequence_advanced',
            'result'=>[
                'sequence_id'=>$sequence['sequence_id'],'touch_number'=>$nextTouch,
                'recommendation_id'=>$recommendationId,'parent_recommendation_id'=>$latestStep['recommendation_id'],
            ],
        ]);

        return [
            'action'=>'advance','sequence_id'=>$sequence['sequence_id'],
            'touch_number'=>$nextTouch,'recommendation_id'=>$recommendationId,
        ];
    }

    private function effectivePolicy(string $organizationId):OutreachSequencePolicy
    {
        $profile=$this->repository->latestProfile($organizationId);
        return $profile===null?$this->safeDefault():$this->policyFromProfile($profile);
    }

    private function policyFromProfile(array $profile):OutreachSequencePolicy
    {
        return new OutreachSequencePolicy(
            (bool)$profile['enabled'],
            array_values(array_map('strval',$profile['allowed_channels']??[])),
            (int)$profile['max_touches'],
            (int)$profile['follow_up_delay_hours'],
            (int)$profile['max_advances_per_run'],
        );
    }

    private function safeDefault():OutreachSequencePolicy
    {
        return new OutreachSequencePolicy(false,['email','linkedin'],3,72,10);
    }

    private function stepId(string $sequenceId,int $touch):string
    {
        return 'GSQS-'.strtoupper(substr(hash('sha256',$sequenceId.':'.$touch),0,20));
    }

    private function boolean(mixed $value,string $field):bool
    {
        if(is_bool($value))return $value;
        if($value===0||$value===1||$value==='0'||$value==='1')return (bool)$value;
        throw new InvalidArgumentException($field.' must be boolean.');
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
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException($field.' must be a list.');
        $out=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException($field.' contains invalid value.');
            $out[trim($item)]=true;
        }
        if($out===[])throw new InvalidArgumentException($field.' must not be empty.');
        return array_keys($out);
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function publish(
        string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,
        string $correlationId,string $actorType,string $actorId
    ):void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,$actorType,$actorId),$this->now(),
        ));
    }

    private function appendAudit(
        string $organizationId,string $actorType,string $actorId,string $subjectType,string $subjectId,
        string $correlationId,array $data
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.engagement_sequence',$actorType,$actorId,
            $subjectType,$subjectId,null,$data,$correlationId,$this->now(),
        ));
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
