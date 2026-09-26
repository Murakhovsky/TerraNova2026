<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\AI\GrowthEngagementPrompt;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementBoundary;
use Domains\Growth\Application\Contract\GrowthEngagementGatewayInterface;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthIntelligenceRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\DTO\EngagementRecommendationDraft;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\EngagementRecommendation;
use Domains\Growth\Domain\EngagementRecommendationStatus;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthEngagementService implements GrowthEngagementBoundary
{
    public function __construct(
        private GrowthRepositoryInterface $growth,
        private GrowthIntelligenceRepositoryInterface $intelligence,
        private GrowthBuyingCommitteeRepositoryInterface $committee,
        private GrowthEngagementRepositoryInterface $engagement,
        private GrowthEngagementGatewayInterface $gateway,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function generateRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey
    ):array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $runId='GERN-'.$this->stableId($organizationId.':engagement_run:'.$idempotencyKey);
        $recommendationId='GERC-'.$this->stableId($organizationId.':engagement_recommendation:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'candidate_id'=>$candidateId,
            'prompt_version'=>GrowthEngagementPrompt::PROMPT_VERSION,
            'schema_version'=>GrowthEngagementPrompt::SCHEMA_VERSION,
        ]);

        $setup=$this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,$runId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'generate_engagement_recommendation',$idempotencyKey,$fingerprint)){
                $run=$this->engagement->viewRun($organizationId,$runId)
                    ?? throw new InvalidArgumentException('Growth engagement receipt exists but run was not found.');
                $storedRecommendationId=$run['recommendation_id']??null;
                $recommendation=null;
                if(is_string($storedRecommendationId)&&$storedRecommendationId!==''){
                    $recommendation=$this->engagement->viewRecommendation($organizationId,$storedRecommendationId)
                        ?? throw new InvalidArgumentException('Growth engagement replay references a missing recommendation.');
                }
                return ['replay'=>['run'=>$run,'recommendation'=>$recommendation,'replayed'=>true]];
            }

            $candidate=$this->growth->viewCandidate($organizationId,$candidateId)
                ?? throw new InvalidArgumentException('Growth candidate was not found.');
            $this->assertCandidateEligible($candidate);
            $context=$this->context($organizationId,$candidate);
            $this->engagement->createRun(
                $organizationId,$runId,$candidateId,$context,
                GrowthEngagementPrompt::PROMPT_VERSION,GrowthEngagementPrompt::SCHEMA_VERSION,$actorId,
            );
            $this->publish(GrowthEventType::ENGAGEMENT_RUN_STARTED,$organizationId,'growth_candidate',$candidateId,[
                'run_id'=>$runId,'prompt_version'=>GrowthEngagementPrompt::PROMPT_VERSION,
                'schema_version'=>GrowthEngagementPrompt::SCHEMA_VERSION,
            ],$actorId,$correlationId);

            return ['replay'=>null,'candidate'=>$candidate,'context'=>$context];
        });

        if(is_array($setup['replay']??null))return $setup['replay'];
        $candidate=$setup['candidate']??null;
        $context=$setup['context']??null;
        if(!is_array($candidate)||!is_array($context))throw new InvalidArgumentException('Growth engagement setup is invalid.');

        try{
            $draft=$this->gateway->recommend($organizationId,$candidateId,$correlationId,$context);
            $this->validateDraft($draft,$candidate,$context);
        }catch(Throwable $error){
            $summary=mb_substr(trim($error->getMessage())!==''?get_class($error).': '.$error->getMessage():get_class($error),0,2000);
            return $this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$candidateId,$runId,$idempotencyKey,$summary
            ):array{
                $this->engagement->failRun($organizationId,$runId,$summary);
                $this->publish(GrowthEventType::ENGAGEMENT_RUN_FAILED,$organizationId,'growth_candidate',$candidateId,[
                    'run_id'=>$runId,'error'=>$summary,
                ],$actorId,$correlationId);
                $this->appendAudit($organizationId,$actorId,$correlationId,'growth.engagement.failed','growth_candidate',$candidateId,$idempotencyKey,[
                    'run_id'=>$runId,'error'=>$summary,
                ]);
                return [
                    'run'=>$this->engagement->viewRun($organizationId,$runId)
                        ?? throw new InvalidArgumentException('Failed Growth engagement run could not be read back.'),
                    'recommendation'=>null,
                ];
            });
        }

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,$runId,$recommendationId,$draft
        ):array{
            // Serialize competing recommendation completions per Candidate.
            $this->growth->lockCandidate($organizationId,$candidateId);
            $superseded=$this->engagement->supersedeProposedForCandidate(
                $organizationId,$candidateId,'Superseded by a newer recommendation.',$actorId,
            );
            foreach($superseded as $supersededId){
                $this->publish(GrowthEventType::ENGAGEMENT_RECOMMENDATION_SUPERSEDED,$organizationId,'growth_candidate',$candidateId,[
                    'recommendation_id'=>$supersededId,'replacement_recommendation_id'=>$recommendationId,
                ],$actorId,$correlationId);
            }
            $recommendation=new EngagementRecommendation(
                $recommendationId,OrganizationId::fromString($organizationId),$candidateId,
                $draft->actionType,$draft->channel,$draft->contactId,$draft->rationale,$draft->messageAngle,
                $draft->evidenceIds,$draft->unknowns,$draft->confidence,$draft->provider,$draft->model,
                $draft->promptVersion,$draft->schemaVersion,$this->now(),
            );
            $this->engagement->createRecommendation($recommendation,$runId,$actorId);
            $this->engagement->completeRun(
                $organizationId,$runId,$recommendationId,$draft->provider,$draft->model,
                $draft->inputTokens,$draft->outputTokens,$draft->costAmount,$draft->costCurrency,
            );
            $this->publish(GrowthEventType::ENGAGEMENT_RUN_COMPLETED,$organizationId,'growth_candidate',$candidateId,[
                'run_id'=>$runId,'recommendation_id'=>$recommendationId,
                'action_type'=>$draft->actionType->value,'channel'=>$draft->channel->value,
            ],$actorId,$correlationId);
            $this->publish(GrowthEventType::ENGAGEMENT_RECOMMENDATION_CREATED,$organizationId,'growth_candidate',$candidateId,[
                'recommendation_id'=>$recommendationId,'action_type'=>$draft->actionType->value,
                'channel'=>$draft->channel->value,'contact_id'=>$draft->contactId,'confidence'=>$draft->confidence,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.engagement.recommended','growth_candidate',$candidateId,$idempotencyKey,[
                'run_id'=>$runId,'recommendation_id'=>$recommendationId,'action_type'=>$draft->actionType->value,
                'channel'=>$draft->channel->value,'contact_id'=>$draft->contactId,
            ]);

            return [
                'run'=>$this->engagement->viewRun($organizationId,$runId)
                    ?? throw new InvalidArgumentException('Completed Growth engagement run could not be read back.'),
                'recommendation'=>$this->engagement->viewRecommendation($organizationId,$recommendationId)
                    ?? throw new InvalidArgumentException('Created Growth engagement recommendation could not be read back.'),
            ];
        });
    }

    public function acceptRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $reason,string $idempotencyKey
    ):array {
        return $this->decide(
            true,$organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$reason,$idempotencyKey,
        );
    }

    public function dismissRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $reason,string $idempotencyKey
    ):array {
        return $this->decide(
            false,$organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$reason,$idempotencyKey,
        );
    }

    public function engagementBrief(string $organizationId,string $candidateId):array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        return [
            'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                ?? throw new InvalidArgumentException('Growth candidate was not found.'),
            'latest_recommendation'=>$this->engagement->latestRecommendation($organizationId,$candidateId),
        ];
    }

    /** @return array<string,mixed> */
    private function decide(
        bool $accept,string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,
        string $reason,string $idempotencyKey
    ):array {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $recommendationId=$this->bounded(trim($recommendationId),'recommendationId',80);
        $reason=$this->bounded(trim($reason),'reason',2000);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $operation=$accept?'accept_engagement_recommendation':'dismiss_engagement_recommendation';
        $fingerprint=$this->fingerprint([
            'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,'reason'=>$reason,'decision'=>$accept?'accept':'dismiss',
        ]);

        return $this->transactions->transactional(function()use(
            $accept,$organizationId,$actorId,$correlationId,$candidateId,$recommendationId,$reason,$idempotencyKey,$operation,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return [
                    'recommendation'=>$this->engagement->viewRecommendation($organizationId,$recommendationId)
                        ?? throw new InvalidArgumentException('Growth engagement decision replay recommendation was not found.'),
                    'replayed'=>true,
                ];
            }

            $recommendation=$this->engagement->lockRecommendation($organizationId,$recommendationId);
            if($recommendation->candidateId!==$candidateId){
                throw new InvalidArgumentException('Growth engagement recommendation belongs to another candidate.');
            }
            if($accept)$recommendation->accept($reason); else $recommendation->dismiss($reason);
            $this->engagement->updateRecommendation($recommendation,$actorId);
            $event=$accept?GrowthEventType::ENGAGEMENT_RECOMMENDATION_ACCEPTED:GrowthEventType::ENGAGEMENT_RECOMMENDATION_DISMISSED;
            $action=$accept?'growth.engagement.accepted':'growth.engagement.dismissed';
            $this->publish($event,$organizationId,'growth_candidate',$candidateId,[
                'recommendation_id'=>$recommendationId,'action_type'=>$recommendation->actionType->value,
                'channel'=>$recommendation->channel->value,'reason'=>$reason,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,$action,'growth_candidate',$candidateId,$idempotencyKey,[
                'recommendation_id'=>$recommendationId,'reason'=>$reason,
            ]);
            return [
                'recommendation'=>$this->engagement->viewRecommendation($organizationId,$recommendationId)
                    ?? throw new InvalidArgumentException('Growth engagement recommendation could not be read back.'),
            ];
        });
    }

    /** @param array<string,mixed> $candidate */
    private function assertCandidateEligible(array $candidate):void
    {
        $allowed=[
            OpportunityCandidateStatus::Researched->value,
            OpportunityCandidateStatus::Scored->value,
            OpportunityCandidateStatus::Qualified->value,
            OpportunityCandidateStatus::ReadyForHandoff->value,
            OpportunityCandidateStatus::Monitoring->value,
            OpportunityCandidateStatus::RejectedByTargetDomain->value,
        ];
        if(!in_array((string)($candidate['status']??''),$allowed,true)){
            throw new InvalidArgumentException('Growth engagement recommendation requires researched, scored, qualified, monitoring or ready candidate.');
        }
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function context(string $organizationId,array $candidate):array
    {
        $signals=[];
        $allowedEvidence=[];
        foreach($candidate['signal_ids']??[] as $signalId){
            if(!is_string($signalId)||$signalId==='')continue;
            $signal=$this->growth->viewSignal($organizationId,$signalId);
            if($signal!==null){
                $signals[]=$signal;
                $allowedEvidence[]=$signalId;
            }
        }
        if($signals===[])throw new InvalidArgumentException('Growth engagement candidate has no readable Signal evidence.');

        $account=null;
        $committee=null;
        $allowedContacts=[];
        $subjectType=(string)($candidate['subject_type']??'');
        $subjectId=(string)($candidate['subject_id']??'');

        if($subjectType==='account'&&$subjectId!==''){
            $account=[
                'account'=>$this->intelligence->viewAccount($organizationId,$subjectId),
                'latest_snapshot'=>$this->intelligence->latestSnapshot($organizationId,$subjectId)?->toArray(),
                'icp_match'=>$this->intelligence->latestIcpMatch($organizationId,$subjectId),
            ];
            $contacts=[];
            foreach($this->committee->accountContacts($organizationId,$subjectId) as $contact){
                $contactId=$contact['contact_id']??null;
                if(!is_string($contactId)||$contactId==='')continue;
                $identityType=is_string($contact['identity_type']??null)?strtolower(trim((string)$contact['identity_type'])):'';
                $availableChannels=match($identityType){
                    'email'=>['email'],
                    'linkedin'=>['linkedin'],
                    default=>[],
                };
                $allowedContacts[]=$contactId;
                $contacts[]=[
                    'contact_id'=>$contactId,
                    'available_channels'=>$availableChannels,
                    'latest_snapshot'=>$contact['latest_snapshot']??null,
                ];
            }
            $committee=[
                'assessment'=>$this->committee->latestAssessment($organizationId,$subjectId),
                'contacts'=>$contacts,
            ];
        }elseif($subjectType==='contact'&&$subjectId!==''){
            $allowedContacts[]=$subjectId;
        }

        $allowedContacts=array_values(array_unique($allowedContacts));
        sort($allowedContacts,SORT_STRING);
        $allowedEvidence=array_values(array_unique($allowedEvidence));
        sort($allowedEvidence,SORT_STRING);

        return [
            'candidate'=>[
                'candidate_id'=>$candidate['candidate_id']??null,
                'opportunity_type'=>$candidate['opportunity_type']??null,
                'growth_mode'=>$candidate['growth_mode']??null,
                'subject_type'=>$subjectType,'subject_id'=>$subjectId,
                'target_domain'=>$candidate['target_domain']??null,'status'=>$candidate['status']??null,
                'rationale'=>$candidate['rationale']??null,'score'=>$candidate['score']??null,
                'expected_value'=>$candidate['expected_value']??null,
                'recommended_play'=>$candidate['recommended_play']??null,
            ],
            'signals'=>$signals,
            'account_intelligence'=>$account,
            'buying_committee'=>$committee,
            'allowed_evidence_ids'=>$allowedEvidence,
            'allowed_contact_ids'=>$allowedContacts,
            'allowed_action_types'=>\Domains\Growth\Domain\NextBestActionType::values(),
            'allowed_channels'=>\Domains\Growth\Domain\EngagementChannel::values(),
        ];
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $context */
    private function validateDraft(EngagementRecommendationDraft $draft,array $candidate,array $context):void
    {
        $allowedEvidence=array_fill_keys(array_values(array_filter($context['allowed_evidence_ids']??[],'is_string')),true);
        foreach($draft->evidenceIds as $evidenceId){
            if(!isset($allowedEvidence[$evidenceId])){
                throw new InvalidArgumentException('Growth engagement LLM cited evidence outside Candidate Signal set: '.$evidenceId);
            }
        }

        if($draft->contactId!==null){
            $allowedContacts=array_fill_keys(array_values(array_filter($context['allowed_contact_ids']??[],'is_string')),true);
            if(!isset($allowedContacts[$draft->contactId])){
                throw new InvalidArgumentException('Growth engagement LLM selected contact outside Candidate account context.');
            }
        }

        if(in_array($draft->actionType->value,['ignore','monitor','create_report'],true)&&$draft->contactId!==null){
            throw new InvalidArgumentException('Growth engagement non-contact action must not select a contact.');
        }
        if(in_array($draft->actionType->value,['connect_linkedin','send_email','call'],true)&&$draft->contactId===null){
            throw new InvalidArgumentException('Growth engagement direct-contact action requires contact_id.');
        }
        if($draft->contactId!==null&&in_array($draft->actionType->value,['connect_linkedin','send_email','call'],true)){
            $available=[];
            foreach(($context['buying_committee']['contacts']??[]) as $contact){
                if(!is_array($contact)||($contact['contact_id']??null)!==$draft->contactId)continue;
                foreach($contact['available_channels']??[] as $channel){
                    if(is_string($channel)&&$channel!=='')$available[$channel]=true;
                }
            }
            if(!isset($available[$draft->channel->value])){
                throw new InvalidArgumentException('Growth engagement selected channel is not available for the selected contact.');
            }
        }
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,int $actorId,string $correlationId):void
    {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,int $actorId,string $correlationId,string $action,string $subjectType,
        string $subjectId,string $idempotencyKey,array $data=[]
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.engagement','USER',(string)$actorId,
            $subjectType,$subjectId,null,['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],
            $correlationId,$this->now(),
        ));
    }

    private function stableId(string $value):string{return strtoupper(substr(hash('sha256',$value),0,20));}

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value):string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
