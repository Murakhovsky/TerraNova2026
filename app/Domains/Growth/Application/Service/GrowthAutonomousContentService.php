<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\AI\GrowthAutonomousContentPrompt;
use Domains\Growth\Application\Contract\GrowthAutonomousContentBoundary;
use Domains\Growth\Application\Contract\GrowthAutonomousContentGatewayInterface;
use Domains\Growth\Application\Contract\GrowthAutonomousContentRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthAutonomousOutreachBoundary;
use Domains\Growth\Application\Contract\GrowthAutonomousOutreachRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthIntelligenceRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceGuardInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\DTO\AutonomousContentDraft;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\AutonomousContentReviewMode;
use Domains\Growth\Domain\AutonomousContentReviewPolicy;
use Domains\Growth\Domain\EngagementRecommendationStatus;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthAutonomousContentService implements GrowthAutonomousContentBoundary
{
    public function __construct(
        private GrowthAutonomousContentRepositoryInterface $repository,
        private GrowthAutonomousContentGatewayInterface $gateway,
        private GrowthRepositoryInterface $growth,
        private GrowthEngagementRepositoryInterface $engagement,
        private GrowthBuyingCommitteeRepositoryInterface $committee,
        private GrowthIntelligenceRepositoryInterface $intelligence,
        private GrowthAutonomousOutreachRepositoryInterface $autonomyRepository,
        private GrowthAutonomousOutreachBoundary $autonomy,
        private GrowthEngagementExecutionRepositoryInterface $executions,
        private GrowthOutreachSequenceGuardInterface $sequenceGuard,
        private GrowthMutationReceiptInterface $receipts,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function viewReviewPolicy(string $organizationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $profile=$this->repository->latestReviewProfile($organizationId);
        $policy=$profile===null?$this->safeDefault():$this->policyFromProfile($profile);
        return [
            'source'=>$profile===null?'safe_default':'tenant_profile',
            'profile'=>$profile,
            'effective'=>$policy->toArray(),
            'defaults'=>$this->safeDefault()->toArray(),
        ];
    }

    public function updateReviewPolicy(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $reason=$this->bounded((string)($input['reason']??''),'reason',1000);
        $modes=$input['channel_modes']??null;
        if(!is_array($modes)||array_is_list($modes))throw new InvalidArgumentException('channel_modes must be an object.');
        $policy=new AutonomousContentReviewPolicy(
            $modes,
            $this->number($input['min_draft_confidence']??null,'min_draft_confidence'),
            $this->integer($input['max_body_chars']??null,'max_body_chars'),
        );
        $fingerprint=hash('sha256',json_encode($policy->toArray()+['reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));

        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$idempotencyKey,$reason,$policy,$fingerprint):array{
            $this->executions->lockPreHandoffCapacity($organizationId);
            if(!$this->receipts->claim($organizationId,'engagement_content_review_policy_update',$idempotencyKey,$fingerprint)){
                return $this->viewReviewPolicy($organizationId)+['replayed'=>true];
            }
            $latest=$this->repository->latestReviewProfile($organizationId);
            $current=$latest===null?null:$this->policyFromProfile($latest);
            if($current!==null&&$current->toArray()===$policy->toArray()){
                throw new InvalidArgumentException('Growth content review policy is unchanged.');
            }
            $revision=(int)($latest['revision']??0)+1;
            $profileId='GCRP-'.strtoupper(substr(hash('sha256',$organizationId.':'.$revision),0,20));
            $profile=[
                'organization_id'=>$organizationId,'profile_id'=>$profileId,'revision'=>$revision,
                ...$policy->toArray(),'reason'=>$reason,'created_by'=>$actorId,'created_at'=>$this->now()->format(DATE_ATOM),
            ];
            $this->repository->appendReviewProfile($profile);
            $this->publish(GrowthEventType::ENGAGEMENT_CONTENT_REVIEW_PROFILE_UPDATED,$organizationId,'growth_engagement_content_review_profile',$profileId,[
                'revision'=>$revision,'policy'=>$policy->toArray(),
            ],$correlationId,'USER',(string)$actorId);
            $this->appendAudit($organizationId,'growth.engagement_content_review','USER',(string)$actorId,'growth_engagement_content_review_profile',$profileId,$correlationId,[
                'action'=>'growth.engagement.content_review_policy_updated','idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                'result'=>['revision'=>$revision,'policy'=>$policy->toArray(),'reason'=>$reason],
            ]);
            return $this->viewReviewPolicy($organizationId);
        });
    }

    public function generateDraft(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $idempotencyKey,string $actorType='USER'
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $candidateId=$this->bounded($candidateId,'candidateId',80);
        $recommendationId=$this->bounded($recommendationId,'recommendationId',80);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $actorType=strtoupper(trim($actorType));
        if(!in_array($actorType,['USER','SYSTEM'],true))throw new InvalidArgumentException('Growth content actor type is invalid.');
        $runId='GCDR-'.strtoupper(substr(hash('sha256',$organizationId.':'.$idempotencyKey),0,20));
        $draftId='GCD-'.strtoupper(substr(hash('sha256',$organizationId.':'.$recommendationId.':'.$idempotencyKey),0,20));
        $fingerprint=hash('sha256',json_encode([
            'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,
            'prompt_version'=>GrowthAutonomousContentPrompt::PROMPT_VERSION,
            'schema_version'=>GrowthAutonomousContentPrompt::SCHEMA_VERSION,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));

        $setup=$this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$idempotencyKey,$runId,$fingerprint,$actorType
        ):array{
            if(!$this->receipts->claim($organizationId,'generate_engagement_content_draft',$idempotencyKey,$fingerprint)){
                $run=$this->repository->viewRun($organizationId,$runId)
                    ??throw new InvalidArgumentException('Growth content generation receipt exists but run was not found.');
                $storedDraftId=$run['draft_id']??null;
                return [
                    'replay'=>[
                        'run'=>$run,
                        'draft'=>is_string($storedDraftId)&&$storedDraftId!==''?$this->repository->viewDraft($organizationId,$storedDraftId):null,
                        'replayed'=>true,
                    ],
                ];
            }

            $recommendation=$this->recommendation($organizationId,$candidateId,$recommendationId);
            $this->assertActiveSequenceRecommendation($organizationId,$recommendationId);
            $this->assertDraftableRecommendation($recommendation);
            if($this->executions->byRecommendation($organizationId,$recommendationId)!==null){
                throw new InvalidArgumentException('Cannot generate Growth content after execution was proposed.');
            }
            if($this->autonomyRepository->latestPayload($organizationId,$recommendationId)!==null){
                throw new InvalidArgumentException('Cannot generate Growth content after an autonomous payload was staged.');
            }
            $latestDraft=$this->repository->latestDraft($organizationId,$recommendationId);
            if(is_array($latestDraft)&&($latestDraft['status']??null)==='pending_review'){
                throw new InvalidArgumentException('Growth content already has a draft pending human review.');
            }
            $policy=$this->reviewPolicy($organizationId);
            if(!$policy->modeFor((string)$recommendation['channel'])->canDraft()){
                throw new InvalidArgumentException('Generated Growth content is blocked by the tenant review policy for this channel.');
            }
            $contextBundle=$this->context($organizationId,$candidateId,$recommendation);
            $context=$contextBundle['llm_context'];
            $internalReferences=$contextBundle['internal_reference_ids'];
            $this->repository->createRun(
                $organizationId,$runId,$candidateId,$recommendationId,$context,
                GrowthAutonomousContentPrompt::PROMPT_VERSION,GrowthAutonomousContentPrompt::SCHEMA_VERSION,$actorId,
            );
            $this->publish(GrowthEventType::ENGAGEMENT_CONTENT_RUN_STARTED,$organizationId,'growth_candidate',$candidateId,[
                'run_id'=>$runId,'recommendation_id'=>$recommendationId,
                'prompt_version'=>GrowthAutonomousContentPrompt::PROMPT_VERSION,'schema_version'=>GrowthAutonomousContentPrompt::SCHEMA_VERSION,
            ],$correlationId,$actorType,(string)$actorId);
            return ['replay'=>null,'context'=>$context,'internal_references'=>$internalReferences];
        });

        if(is_array($setup['replay']??null))return $setup['replay'];
        $context=$setup['context']??null;
        $internalReferences=$setup['internal_references']??null;
        if(!is_array($context)||!is_array($internalReferences))throw new InvalidArgumentException('Growth content generation context is invalid.');

        try{
            $draft=$this->gateway->draft($organizationId,$candidateId,$recommendationId,$correlationId,$context);
            $riskFlags=$this->validatedRiskFlags($draft,$context,$internalReferences);

            return $this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$idempotencyKey,$runId,$draftId,$draft,$riskFlags,$actorType
            ):array{
                $this->executions->lockPreHandoffCapacity($organizationId);
                $recommendation=$this->recommendation($organizationId,$candidateId,$recommendationId);
                $this->assertActiveSequenceRecommendation($organizationId,$recommendationId);
                $this->assertDraftableRecommendation($recommendation);
                if($this->executions->byRecommendation($organizationId,$recommendationId)!==null){
                    throw new InvalidArgumentException('Growth content execution appeared while the draft was being generated.');
                }
                if($this->autonomyRepository->latestPayload($organizationId,$recommendationId)!==null){
                    throw new InvalidArgumentException('Growth autonomous payload appeared while the draft was being generated.');
                }

                $policy=$this->reviewPolicy($organizationId);
                $review=$policy->evaluate((string)$recommendation['channel'],$draft->confidence,$riskFlags,$draft->body);
                $latest=$this->repository->latestDraft($organizationId,$recommendationId);
                $revision=(int)($latest['revision']??0)+1;
                $status='pending_review';$decisionReason=null;$decidedBy=null;$decidedAt=null;

                if(($review['mode']??'')===AutonomousContentReviewMode::Blocked->value){
                    $status='blocked';$decisionReason=(string)$review['reason'];$decidedBy=$actorId;$decidedAt=$this->now()->format(DATE_ATOM);
                }elseif(!empty($review['auto_approve'])){
                    $this->autonomy->stagePayload(
                        $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$draft->body,
                        'Auto-approved by Growth autonomous content review policy.',
                        'content-auto-stage-'.$draftId,$actorType,
                    );
                    $status='approved';$decisionReason=(string)$review['reason'];$decidedBy=$actorId;$decidedAt=$this->now()->format(DATE_ATOM);
                }

                $createdAt=$this->now()->format(DATE_ATOM);
                $stored=[
                    'organization_id'=>$organizationId,'draft_id'=>$draftId,'run_id'=>$runId,'recommendation_id'=>$recommendationId,
                    'candidate_id'=>$candidateId,'revision'=>$revision,'channel'=>(string)$recommendation['channel'],'body'=>$draft->body,
                    'evidence_ids'=>$draft->evidenceIds,'risk_flags'=>$riskFlags,'confidence'=>$draft->confidence,
                    'provider'=>$draft->provider,'model'=>$draft->model,'prompt_version'=>$draft->promptVersion,'schema_version'=>$draft->schemaVersion,
                    'status'=>$status,'review_code'=>(string)$review['code'],'decision_reason'=>$decisionReason,'generated_by'=>$actorId,
                    'decided_by'=>$decidedBy,'created_at'=>$createdAt,'decided_at'=>$decidedAt,'updated_at'=>$createdAt,
                ];
                $this->repository->appendDraft($stored);
                $this->repository->completeRun(
                    $organizationId,$runId,$draftId,$draft->provider,$draft->model,
                    $draft->inputTokens,$draft->outputTokens,$draft->costAmount,$draft->costCurrency,
                );
                $this->publish(GrowthEventType::ENGAGEMENT_CONTENT_DRAFT_CREATED,$organizationId,'growth_candidate',$candidateId,[
                    'run_id'=>$runId,'draft_id'=>$draftId,'recommendation_id'=>$recommendationId,'revision'=>$revision,
                    'channel'=>$recommendation['channel'],'confidence'=>$draft->confidence,'risk_flags'=>$riskFlags,'review_code'=>$review['code'],
                ],$correlationId,$actorType,(string)$actorId);
                if($status==='approved'){
                    $this->publish(GrowthEventType::ENGAGEMENT_CONTENT_DRAFT_APPROVED,$organizationId,'growth_candidate',$candidateId,[
                        'draft_id'=>$draftId,'recommendation_id'=>$recommendationId,'review_code'=>$review['code'],'auto_approved'=>true,
                    ],$correlationId,$actorType,(string)$actorId);
                }elseif($status==='blocked'){
                    $this->publish(GrowthEventType::ENGAGEMENT_CONTENT_DRAFT_BLOCKED,$organizationId,'growth_candidate',$candidateId,[
                        'draft_id'=>$draftId,'recommendation_id'=>$recommendationId,'review_code'=>$review['code'],
                    ],$correlationId,$actorType,(string)$actorId);
                }
                $this->publish(GrowthEventType::ENGAGEMENT_CONTENT_RUN_COMPLETED,$organizationId,'growth_candidate',$candidateId,[
                    'run_id'=>$runId,'draft_id'=>$draftId,'recommendation_id'=>$recommendationId,'status'=>$status,
                ],$correlationId,$actorType,(string)$actorId);
                $this->appendAudit($organizationId,'growth.engagement_content',$actorType,(string)$actorId,'growth_candidate',$candidateId,$correlationId,[
                    'action'=>'growth.engagement.content_draft_generated','idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                    'input_references'=>['recommendation_id'=>$recommendationId],
                    'result'=>['run_id'=>$runId,'draft_id'=>$draftId,'status'=>$status,'review_code'=>$review['code'],'risk_flags'=>$riskFlags],
                ]);
                return $this->contentBrief($organizationId,$candidateId,$recommendationId)+[
                    'run'=>$this->repository->viewRun($organizationId,$runId),
                ];
            });
        }catch(Throwable $error){
            $summary=mb_substr(trim($error->getMessage())!==''?get_class($error).': '.$error->getMessage():get_class($error),0,2000);
            $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$runId,$summary,$actorType):void{
                $run=$this->repository->viewRun($organizationId,$runId);
                if(is_array($run)&&($run['status']??null)==='running'){
                    $this->repository->failRun($organizationId,$runId,$summary);
                    $this->publish(GrowthEventType::ENGAGEMENT_CONTENT_RUN_FAILED,$organizationId,'growth_candidate',$candidateId,[
                        'run_id'=>$runId,'recommendation_id'=>$recommendationId,'error'=>$summary,
                    ],$correlationId,$actorType,(string)$actorId);
                }
            });
            throw $error;
        }
    }

    public function contentBrief(string $organizationId,string $candidateId,string $recommendationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $candidateId=$this->bounded($candidateId,'candidateId',80);
        $recommendationId=$this->bounded($recommendationId,'recommendationId',80);
        $recommendation=$this->recommendation($organizationId,$candidateId,$recommendationId);
        return [
            'recommendation_id'=>$recommendationId,
            'channel'=>$recommendation['channel']??null,
            'review_policy'=>$this->viewReviewPolicy($organizationId),
            'latest_draft'=>$this->repository->latestDraft($organizationId,$recommendationId),
            'payload_staged'=>$this->autonomyRepository->latestPayload($organizationId,$recommendationId)!==null,
        ];
    }

    public function approveDraft(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $draftId,string $reason,string $idempotencyKey
    ):array {
        return $this->decideDraft(true,$organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$draftId,$reason,$idempotencyKey);
    }

    public function rejectDraft(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $draftId,string $reason,string $idempotencyKey
    ):array {
        return $this->decideDraft(false,$organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$draftId,$reason,$idempotencyKey);
    }

    private function decideDraft(
        bool $approve,string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $draftId,string $reason,string $idempotencyKey
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $candidateId=$this->bounded($candidateId,'candidateId',80);$recommendationId=$this->bounded($recommendationId,'recommendationId',80);
        $draftId=$this->bounded($draftId,'draftId',80);$reason=$this->bounded($reason,'reason',1000);$idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $fingerprint=hash('sha256',json_encode([
            'draft_id'=>$draftId,'recommendation_id'=>$recommendationId,'decision'=>$approve?'approve':'reject','reason'=>$reason,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $operation=$approve?'engagement_content_draft_approve':'engagement_content_draft_reject';

        return $this->transactions->transactional(function()use(
            $approve,$organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$draftId,$reason,$idempotencyKey,$fingerprint,$operation
        ):array{
            $this->executions->lockPreHandoffCapacity($organizationId);
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return $this->contentBrief($organizationId,$candidateId,$recommendationId)+['replayed'=>true];
            }
            $draft=$this->repository->lockDraft($organizationId,$draftId);
            if($approve)$this->assertActiveSequenceRecommendation($organizationId,$recommendationId);
            if((string)$draft['candidate_id']!==$candidateId||(string)$draft['recommendation_id']!==$recommendationId){
                throw new InvalidArgumentException('Growth content draft belongs to another recommendation.');
            }
            if((string)$draft['status']!=='pending_review')throw new InvalidArgumentException('Growth content draft is no longer pending review.');
            $recommendation=$this->recommendation($organizationId,$candidateId,$recommendationId);
            $policy=$this->reviewPolicy($organizationId);
            $mode=$policy->modeFor((string)$recommendation['channel']);
            if($approve&&!$mode->canHumanApprove()){
                throw new InvalidArgumentException('Current tenant content policy blocks approval for this channel.');
            }
            if($approve){
                if($this->executions->byRecommendation($organizationId,$recommendationId)!==null){
                    throw new InvalidArgumentException('Cannot approve content after execution was proposed.');
                }
                $this->autonomy->stagePayload(
                    $organizationId,$actorId,$correlationId,$candidateId,$recommendationId,(string)$draft['body'],
                    'Human-approved generated Growth content: '.$reason,'content-human-stage-'.$draftId,
                );
                $this->repository->decideDraft($organizationId,$draftId,'approved',$reason,$actorId,'human_approved');
                $event=GrowthEventType::ENGAGEMENT_CONTENT_DRAFT_APPROVED;$action='growth.engagement.content_draft_approved';
            }else{
                $this->repository->decideDraft($organizationId,$draftId,'rejected',$reason,$actorId,'human_rejected');
                $event=GrowthEventType::ENGAGEMENT_CONTENT_DRAFT_REJECTED;$action='growth.engagement.content_draft_rejected';
            }
            $this->publish($event,$organizationId,'growth_candidate',$candidateId,[
                'draft_id'=>$draftId,'recommendation_id'=>$recommendationId,'reason'=>$reason,'auto_approved'=>false,
            ],$correlationId,'USER',(string)$actorId);
            $this->appendAudit($organizationId,'growth.engagement_content_review','USER',(string)$actorId,'growth_candidate',$candidateId,$correlationId,[
                'action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),
                'input_references'=>['recommendation_id'=>$recommendationId,'draft_id'=>$draftId],'result'=>['reason'=>$reason],
            ]);
            return $this->contentBrief($organizationId,$candidateId,$recommendationId);
        });
    }

    /** @param array<string,mixed> $recommendation @return array{llm_context:array<string,mixed>,internal_reference_ids:list<string>} */
    private function context(string $organizationId,string $candidateId,array $recommendation):array
    {
        $candidate=$this->growth->viewCandidate($organizationId,$candidateId)
            ??throw new InvalidArgumentException('Growth candidate was not found.');
        $allowedEvidence=[];$signals=[];
        foreach(($recommendation['evidence_ids']??[]) as $signalId){
            if(!is_string($signalId)||trim($signalId)==='')continue;
            $signal=$this->growth->viewSignal($organizationId,$signalId);
            if($signal!==null){
                $signals[]=[
                    'signal_id'=>$signalId,
                    'signal_type'=>$signal['signal_type']??null,
                    'title'=>$signal['title']??null,
                    'summary'=>$signal['summary']??null,
                    'facts'=>$signal['facts']??($signal['facts_json']??null),
                    'observed_at'=>$signal['observed_at']??null,
                ];
                $allowedEvidence[]=$signalId;
            }
        }
        if($signals===[])throw new InvalidArgumentException('Growth content drafting requires readable recommendation evidence.');

        $contact=null;$contactId=trim((string)($recommendation['contact_id']??''));
        if($contactId!==''){
            $stored=$this->committee->viewContact($organizationId,$contactId);
            if($stored!==null){
                $contact=['full_name'=>$stored['full_name']??null,'identity_type'=>$stored['identity_type']??null];
            }
        }

        $account=null;$subjectType=(string)($candidate['subject_type']??'');$subjectId=(string)($candidate['subject_id']??'');
        if($subjectType==='account'&&$subjectId!==''){
            $accountRow=$this->intelligence->viewAccount($organizationId,$subjectId);
            $snapshot=$this->intelligence->latestSnapshot($organizationId,$subjectId);
            $snapshotRow=$snapshot?->toArray();
            foreach(['organization_id','account_id','created_by','updated_by','created_at','updated_at'] as $field){
                if(is_array($accountRow))unset($accountRow[$field]);
                if(is_array($snapshotRow))unset($snapshotRow[$field]);
            }
            if(is_array($snapshotRow))unset($snapshotRow['snapshot_id'],$snapshotRow['source_references']);
            $account=['account'=>$accountRow,'latest_snapshot'=>$snapshotRow];

            if($contactId!==''){
                foreach($this->committee->latestContactSnapshotsForAccount($organizationId,$subjectId) as $contactSnapshot){
                    if($contactSnapshot->contactId!==$contactId)continue;
                    $contact=array_replace($contact??[],[
                        'title'=>$contactSnapshot->title,
                        'department'=>$contactSnapshot->department,
                        'seniority'=>$contactSnapshot->seniority,
                        'buying_roles'=>array_map(static fn($role):string=>$role->value,$contactSnapshot->buyingRoles),
                        'relationship_strength'=>$contactSnapshot->relationshipStrength->value,
                        'relationship_reason'=>$contactSnapshot->relationshipReason,
                    ]);
                    break;
                }
            }
        }

        $allowedEvidence=array_values(array_unique($allowedEvidence));sort($allowedEvidence,SORT_STRING);
        $internalReferences=array_values(array_unique(array_filter([
            $candidateId,
            (string)($recommendation['recommendation_id']??''),
            $subjectId,
            $contactId,
            ...$allowedEvidence,
        ],static fn(string $value):bool=>$value!=='')));

        return [
            'llm_context'=>[
                'candidate'=>[
                    'opportunity_type'=>$candidate['opportunity_type']??null,
                    'growth_mode'=>$candidate['growth_mode']??null,
                    'rationale'=>$candidate['rationale']??null,
                    'recommended_play'=>$candidate['recommended_play']??null,
                ],
                'recommendation'=>[
                    'action_type'=>$recommendation['action_type']??null,
                    'channel'=>$recommendation['channel']??null,
                    'rationale'=>$recommendation['rationale']??null,
                    'message_angle'=>$recommendation['message_angle']??null,
                    'unknowns'=>$recommendation['unknowns']??[],
                ],
                'evidence_signals'=>$signals,
                'account_intelligence'=>$account,
                'contact_context'=>$contact,
                'allowed_evidence_ids'=>$allowedEvidence,
                'allowed_risk_flags'=>GrowthAutonomousContentPrompt::RISK_FLAGS,
            ],
            'internal_reference_ids'=>$internalReferences,
        ];
    }

    /** @param array<string,mixed> $context @param list<string> $internalReferences @return list<string> */
    private function validatedRiskFlags(AutonomousContentDraft $draft,array $context,array $internalReferences):array
    {
        $allowed=array_fill_keys(array_values(array_filter($context['allowed_evidence_ids']??[],'is_string')),true);
        foreach($draft->evidenceIds as $evidenceId){
            if(!isset($allowed[$evidenceId]))throw new InvalidArgumentException('Growth content LLM cited evidence outside recommendation evidence.');
        }
        $flags=array_fill_keys($draft->riskFlags,true);
        foreach(array_values(array_unique(array_filter($internalReferences,static fn(string $value):bool=>$value!==''))) as $internalReference){
            if(stripos($draft->body,$internalReference)!==false)$flags['internal_reference_leak']=true;
        }
        foreach(['candidate_id','recommendation_id','signal_id','evidence_id','growth_candidate','growth_engagement'] as $internalMarker){
            if(stripos($draft->body,$internalMarker)!==false)$flags['internal_reference_leak']=true;
        }
        if(
            preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',$draft->body)===1
            || preg_match('/\+[1-9][0-9\s().-]{7,}/',$draft->body)===1
            || preg_match('~https?://(?:[a-z0-9-]+\.)?linkedin\.com/in/~i',$draft->body)===1
        ){
            $flags['delivery_identity_leak']=true;
        }
        $flags=array_keys($flags);sort($flags,SORT_STRING);
        return $flags;
    }

    /** @return array<string,mixed> */
    private function recommendation(string $organizationId,string $candidateId,string $recommendationId):array
    {
        $recommendation=$this->engagement->viewRecommendation($organizationId,$recommendationId)
            ??throw new InvalidArgumentException('Growth engagement recommendation was not found.');
        if((string)($recommendation['candidate_id']??'')!==$candidateId){
            throw new InvalidArgumentException('Growth engagement recommendation belongs to another Candidate.');
        }
        return $recommendation;
    }

    private function assertActiveSequenceRecommendation(string $organizationId,string $recommendationId):void
    {
        $recommendation=$this->engagement->viewRecommendation($organizationId,$recommendationId);
        if($recommendation===null)return;
        $block=$this->sequenceGuard->blockingForRecommendation($organizationId,$recommendation);
        if($block!==null){
            throw new InvalidArgumentException('Growth content sequence guard blocked recommendation: '.$block['code'].'. '.$block['reason']);
        }
    }

    /** @param array<string,mixed> $recommendation */
    private function assertDraftableRecommendation(array $recommendation):void
    {
        if(!in_array((string)($recommendation['status']??''),[EngagementRecommendationStatus::Proposed->value,EngagementRecommendationStatus::Accepted->value],true)){
            throw new InvalidArgumentException('Growth content drafting requires proposed or accepted recommendation.');
        }
        if(!in_array((string)($recommendation['channel']??''),['email','linkedin','phone'],true)){
            throw new InvalidArgumentException('Growth content drafting supports only direct outreach channels.');
        }
        if(trim((string)($recommendation['contact_id']??''))===''){
            throw new InvalidArgumentException('Growth content drafting requires an explicit contact.');
        }
    }

    private function reviewPolicy(string $organizationId):AutonomousContentReviewPolicy
    {
        $profile=$this->repository->latestReviewProfile($organizationId);
        return $profile===null?$this->safeDefault():$this->policyFromProfile($profile);
    }

    /** @param array<string,mixed> $profile */
    private function policyFromProfile(array $profile):AutonomousContentReviewPolicy
    {
        return new AutonomousContentReviewPolicy(
            $profile['channel_modes']??[],
            (float)($profile['min_draft_confidence']??0.92),
            (int)($profile['max_body_chars']??3000),
        );
    }

    private function safeDefault():AutonomousContentReviewPolicy
    {
        return new AutonomousContentReviewPolicy(
            ['email'=>'human_review','linkedin'=>'human_review','phone'=>'human_review'],0.92,3000
        );
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

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');return $value;
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

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,string $category,string $actorType,string $actorId,string $subjectType,string $subjectId,
        string $correlationId,array $data
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,$category,$actorType,$actorId,$subjectType,$subjectId,null,$data,$correlationId,$this->now(),
        ));
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
