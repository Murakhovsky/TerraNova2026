<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\AI\GrowthResearchPrompt;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthIntelligenceRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthResearchBoundary;
use Domains\Growth\Application\Contract\GrowthResearchGatewayInterface;
use Domains\Growth\Application\Contract\GrowthResearchRepositoryInterface;
use Domains\Growth\Application\DTO\ResearchProposalDraft;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use Domains\Growth\Domain\ResearchProposal;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthResearchService implements GrowthResearchBoundary
{
    public function __construct(
        private GrowthRepositoryInterface $growth,
        private GrowthIntelligenceRepositoryInterface $intelligence,
        private GrowthBuyingCommitteeRepositoryInterface $committee,
        private GrowthResearchRepositoryInterface $research,
        private GrowthResearchGatewayInterface $gateway,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function generateProposal(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey
    ): array {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $runId='GRUN-'.$this->stableId($organizationId.':research_run:'.$idempotencyKey);
        $proposalId='GRSP-'.$this->stableId($organizationId.':research_proposal:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'candidate_id'=>$candidateId,
            'prompt_version'=>GrowthResearchPrompt::PROMPT_VERSION,
            'schema_version'=>GrowthResearchPrompt::SCHEMA_VERSION,
        ]);

        $setup=$this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,$runId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'generate_research_proposal',$idempotencyKey,$fingerprint)){
                $run=$this->research->viewRun($organizationId,$runId)
                    ?? throw new InvalidArgumentException('Growth research receipt exists but run was not found.');
                $storedProposalId=$run['proposal_id']??null;
                $proposal=null;
                if(is_string($storedProposalId)&&$storedProposalId!==''){
                    $proposal=$this->research->viewProposal($organizationId,$storedProposalId)
                        ?? throw new InvalidArgumentException('Growth research replay references a missing proposal.');
                }
                return [
                    'replay'=>[
                        'run'=>$run,
                        'proposal'=>$proposal,
                        'replayed'=>true,
                    ],
                ];
            }

            $candidate=$this->growth->viewCandidate($organizationId,$candidateId)
                ?? throw new InvalidArgumentException('Growth candidate was not found.');
            if(!in_array((string)$candidate['status'],[
                OpportunityCandidateStatus::Detected->value,
                OpportunityCandidateStatus::Enriching->value,
            ],true)){
                throw new InvalidArgumentException('Growth research proposal requires detected or enriching candidate.');
            }
            $context=$this->context($organizationId,$candidate);
            $this->research->createRun(
                $organizationId,$runId,$candidateId,GrowthResearchPrompt::PROMPT_VERSION,
                GrowthResearchPrompt::SCHEMA_VERSION,$context,$actorId,
            );
            $this->publish(GrowthEventType::RESEARCH_RUN_STARTED,$organizationId,'growth_candidate',$candidateId,[
                'run_id'=>$runId,'prompt_version'=>GrowthResearchPrompt::PROMPT_VERSION,
                'schema_version'=>GrowthResearchPrompt::SCHEMA_VERSION,
            ],$actorId,$correlationId);
            return ['replay'=>null,'candidate'=>$candidate,'context'=>$context];
        });

        if(is_array($setup['replay']??null))return $setup['replay'];
        $candidate=$setup['candidate']??throw new InvalidArgumentException('Growth research setup lost Candidate snapshot.');
        $context=$setup['context']??throw new InvalidArgumentException('Growth research setup lost context snapshot.');
        if(!is_array($candidate)||!is_array($context))throw new InvalidArgumentException('Growth research setup is invalid.');

        try{
            $draft=$this->gateway->propose($organizationId,$candidateId,$correlationId,$context);
            $this->validateEvidence($draft,$candidate);
        }catch(Throwable $error){
            $summary=mb_substr(trim($error->getMessage())!==''?get_class($error).': '.$error->getMessage():get_class($error),0,2000);
            return $this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$candidateId,$runId,$idempotencyKey,$summary
            ):array{
                $this->research->failRun($organizationId,$runId,$summary);
                $this->publish(GrowthEventType::RESEARCH_RUN_FAILED,$organizationId,'growth_candidate',$candidateId,[
                    'run_id'=>$runId,'error'=>$summary,
                ],$actorId,$correlationId);
                $this->appendAudit($organizationId,$actorId,$correlationId,'growth.research.failed','growth_candidate',$candidateId,$idempotencyKey,[
                    'run_id'=>$runId,'error'=>$summary,
                ]);
                return ['run'=>$this->research->viewRun($organizationId,$runId),'proposal'=>null];
            });
        }

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,$runId,$proposalId,$draft
        ):array{
            $proposal=new ResearchProposal(
                $proposalId,OrganizationId::fromString($organizationId),$candidateId,
                $draft->whyItMatters,$draft->problemHypothesis,$draft->whyNow,
                $draft->evidenceIds,$draft->counterEvidenceIds,$draft->assumptions,$draft->unknowns,
                $draft->confidence,$draft->provider,$draft->model,$draft->promptVersion,$draft->schemaVersion,$this->now(),
            );
            $this->research->createProposal($proposal,$runId,$actorId);
            $this->research->completeRun(
                $organizationId,$runId,$proposalId,$draft->provider,$draft->model,
                $draft->inputTokens,$draft->outputTokens,$draft->costAmount,$draft->costCurrency,
            );
            $this->publish(GrowthEventType::RESEARCH_RUN_COMPLETED,$organizationId,'growth_candidate',$candidateId,[
                'run_id'=>$runId,'proposal_id'=>$proposalId,'provider'=>$draft->provider,'model'=>$draft->model,
            ],$actorId,$correlationId);
            $this->publish(GrowthEventType::RESEARCH_PROPOSAL_CREATED,$organizationId,'growth_candidate',$candidateId,[
                'run_id'=>$runId,'proposal_id'=>$proposalId,'provider'=>$draft->provider,'model'=>$draft->model,
                'prompt_version'=>$draft->promptVersion,'schema_version'=>$draft->schemaVersion,'confidence'=>$draft->confidence,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.research.proposed','growth_candidate',$candidateId,$idempotencyKey,[
                'run_id'=>$runId,'proposal_id'=>$proposalId,'provider'=>$draft->provider,'model'=>$draft->model,
            ]);
            return [
                'run'=>$this->research->viewRun($organizationId,$runId),
                'proposal'=>$this->research->viewProposal($organizationId,$proposalId),
            ];
        });
    }

    public function acceptProposal(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $proposalId,string $idempotencyKey
    ): array {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $proposalId=$this->bounded(trim($proposalId),'proposalId',80);
        $fingerprint=$this->fingerprint(['candidate_id'=>$candidateId,'proposal_id'=>$proposalId]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$proposalId,$idempotencyKey,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'accept_research_proposal',$idempotencyKey,$fingerprint)){
                $candidateView=$this->growth->viewCandidate($organizationId,$candidateId)
                    ?? throw new InvalidArgumentException('Growth research acceptance receipt exists but Candidate was not found.');
                $proposalView=$this->research->viewProposal($organizationId,$proposalId)
                    ?? throw new InvalidArgumentException('Growth research acceptance receipt exists but proposal was not found.');
                return [
                    'candidate'=>$candidateView,
                    'proposal'=>$proposalView,
                    'replayed'=>true,
                ];
            }

            $proposal=$this->research->lockProposal($organizationId,$proposalId);
            if($proposal->candidateId!==$candidateId)throw new InvalidArgumentException('Growth research proposal belongs to another candidate.');
            $candidate=$this->growth->lockCandidate($organizationId,$candidateId);
            $allowed=array_flip($candidate->signalIds());
            foreach(array_merge($proposal->evidenceIds,$proposal->counterEvidenceIds) as $evidenceId){
                if(!isset($allowed[$evidenceId]))throw new InvalidArgumentException('Growth research proposal contains evidence outside candidate Signal set.');
            }

            $candidate->markResearched($proposal->rationale());
            $this->growth->updateCandidate($candidate,$actorId);
            $this->research->acceptProposal($organizationId,$proposalId,$actorId);
            $this->publish(GrowthEventType::RESEARCH_PROPOSAL_ACCEPTED,$organizationId,'growth_candidate',$candidateId,[
                'proposal_id'=>$proposalId,'provider'=>$proposal->provider,'model'=>$proposal->model,
                'prompt_version'=>$proposal->promptVersion,'schema_version'=>$proposal->schemaVersion,
            ],$actorId,$correlationId);
            $this->publish(GrowthEventType::CANDIDATE_RESEARCHED,$organizationId,'growth_candidate',$candidateId,[
                'proposal_id'=>$proposalId,'confidence'=>$proposal->confidence,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.research.accept','growth_candidate',$candidateId,$idempotencyKey,[
                'proposal_id'=>$proposalId,
            ]);
            return [
                'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                    ?? throw new InvalidArgumentException('Researched Growth candidate could not be read back.'),
                'proposal'=>$this->research->viewProposal($organizationId,$proposalId)
                    ?? throw new InvalidArgumentException('Accepted Growth research proposal could not be read back.'),
            ];
        });
    }

    public function researchBrief(string $organizationId,string $candidateId): array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        return [
            'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                ?? throw new InvalidArgumentException('Growth candidate was not found.'),
            'latest_proposal'=>$this->research->latestProposal($organizationId,$candidateId),
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function context(string $organizationId,array $candidate): array
    {
        $signals=[];
        foreach($candidate['signal_ids']??[] as $signalId){
            if(!is_string($signalId))continue;
            $signal=$this->growth->viewSignal($organizationId,$signalId);
            if($signal!==null)$signals[]=$signal;
        }
        if($signals===[])throw new InvalidArgumentException('Growth research candidate has no readable Signal evidence.');

        $account=null;
        $committee=null;
        if(($candidate['subject_type']??null)==='account'){
            $accountId=(string)($candidate['subject_id']??'');
            if($accountId!==''){
                $account=[
                    'account'=>$this->intelligence->viewAccount($organizationId,$accountId),
                    'latest_snapshot'=>$this->intelligence->latestSnapshot($organizationId,$accountId)?->toArray(),
                    'icp_match'=>$this->intelligence->latestIcpMatch($organizationId,$accountId),
                ];
                $contacts=[];
                foreach($this->committee->accountContacts($organizationId,$accountId) as $contact){
                    $contacts[]=[
                        'contact_id'=>$contact['contact_id']??null,
                        'latest_snapshot'=>$contact['latest_snapshot']??null,
                    ];
                }
                $committee=[
                    'contacts'=>$contacts,
                    'assessment'=>$this->committee->latestAssessment($organizationId,$accountId),
                ];
            }
        }

        return [
            'candidate'=>[
                'candidate_id'=>$candidate['candidate_id']??null,
                'opportunity_type'=>$candidate['opportunity_type']??null,
                'growth_mode'=>$candidate['growth_mode']??null,
                'subject_type'=>$candidate['subject_type']??null,
                'subject_id'=>$candidate['subject_id']??null,
                'target_domain'=>$candidate['target_domain']??null,
            ],
            'signals'=>$signals,
            'account_intelligence'=>$account,
            'buying_committee'=>$committee,
            'allowed_evidence_ids'=>array_values($candidate['signal_ids']??[]),
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function validateEvidence(ResearchProposalDraft $draft,array $candidate): void
    {
        $allowed=array_fill_keys(array_values(array_filter($candidate['signal_ids']??[],'is_string')),true);
        foreach(array_merge($draft->evidenceIds,$draft->counterEvidenceIds) as $evidenceId){
            if(!isset($allowed[$evidenceId])){
                throw new InvalidArgumentException('Growth research LLM cited evidence outside candidate Signal set: '.$evidenceId);
            }
        }
    }

    private function bounded(string $value,string $field,int $limit): string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,int $actorId,string $correlationId): void
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
    ): void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.research','USER',(string)$actorId,$subjectType,$subjectId,null,
            ['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],$correlationId,$this->now(),
        ));
    }

    private function stableId(string $value): string { return strtoupper(substr(hash('sha256',$value),0,20)); }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value): string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private function now(): DateTimeImmutable { return new DateTimeImmutable('now',new DateTimeZone('UTC')); }
}
