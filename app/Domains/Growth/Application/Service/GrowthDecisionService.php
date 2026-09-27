<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthDecisionBoundary;
use Domains\Growth\Application\Contract\GrowthDecisionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use Domains\Growth\Domain\QualificationEvaluation;
use Domains\Growth\Domain\QualificationOutcome;
use Domains\Growth\Domain\QualificationPolicy;
use Domains\Growth\Domain\QualificationPolicyCriteria;
use Domains\Growth\Domain\QualificationPolicyEvaluator;
use Domains\Growth\Domain\QualificationPolicyStatus;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthDecisionService implements GrowthDecisionBoundary
{
    public function __construct(
        private GrowthDecisionRepositoryInterface $decisions,
        private GrowthRepositoryInterface $growth,
        private GrowthMutationReceiptInterface $receipts,
        private QualificationPolicyEvaluator $evaluator,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function createQualificationPolicy(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ): array {
        $name=$this->required($input,'name',191);
        $criteriaRaw=$input['criteria']??null;
        if(!is_array($criteriaRaw))throw new InvalidArgumentException('Growth qualification criteria must be an object.');
        $criteria=QualificationPolicyCriteria::fromArray($criteriaRaw);
        $policyId='GQPL-'.$this->stableId($organizationId.':qualification_policy:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(['name'=>$name,'criteria'=>$criteria->toArray()]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$name,$criteria,$policyId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'create_qualification_policy',$idempotencyKey,$fingerprint)){
                return ($this->decisions->viewPolicy($organizationId,$policyId,1)
                    ?? throw new InvalidArgumentException('Growth qualification policy receipt exists but policy was not found.'))
                    + ['replayed'=>true];
            }
            $policy=QualificationPolicy::draft($policyId,OrganizationId::fromString($organizationId),$name,$criteria);
            $this->decisions->createPolicy($policy,$actorId);
            $this->publish(GrowthEventType::QUALIFICATION_POLICY_DRAFTED,$organizationId,'growth_qualification_policy',$policyId,[
                'revision'=>1,'name'=>$name,'criteria'=>$criteria->toArray(),
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.qualification_policy.create','growth_qualification_policy',$policyId,$idempotencyKey);
            return $this->decisions->viewPolicy($organizationId,$policyId,1)
                ?? throw new InvalidArgumentException('Created Growth qualification policy could not be read back.');
        });
    }

    public function reviseQualificationPolicy(
        string $organizationId,int $actorId,string $correlationId,string $policyId,int $baseRevision,string $idempotencyKey,array $input
    ): array {
        $policyId=$this->bounded(trim($policyId),'policyId',80);
        if($baseRevision<1)throw new InvalidArgumentException('Growth qualification policy base revision must be positive.');
        $name=$this->required($input,'name',191);
        $criteriaRaw=$input['criteria']??null;
        if(!is_array($criteriaRaw))throw new InvalidArgumentException('Growth qualification criteria must be an object.');
        $criteria=QualificationPolicyCriteria::fromArray($criteriaRaw);
        $fingerprint=$this->fingerprint([
            'policy_id'=>$policyId,'base_revision'=>$baseRevision,'name'=>$name,'criteria'=>$criteria->toArray(),
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$policyId,$baseRevision,$idempotencyKey,$name,$criteria,$fingerprint
        ):array{
            $newRevision=$baseRevision+1;
            if(!$this->receipts->claim($organizationId,'revise_qualification_policy',$idempotencyKey,$fingerprint)){
                return ($this->decisions->viewPolicy($organizationId,$policyId,$newRevision)
                    ?? throw new InvalidArgumentException('Growth qualification policy revision receipt exists but revision was not found.'))
                    + ['replayed'=>true];
            }
            $base=$this->decisions->lockPolicy($organizationId,$policyId,$baseRevision);
            if($this->decisions->viewPolicy($organizationId,$policyId,$newRevision)!==null){
                throw new InvalidArgumentException('Growth qualification policy target revision already exists.');
            }
            $revision=$base->revise($name,$criteria);
            $this->decisions->createPolicy($revision,$actorId);
            $this->publish(GrowthEventType::QUALIFICATION_POLICY_REVISED,$organizationId,'growth_qualification_policy',$policyId,[
                'base_revision'=>$baseRevision,'revision'=>$revision->revision,'name'=>$name,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.qualification_policy.revise','growth_qualification_policy',$policyId,$idempotencyKey,[
                'base_revision'=>$baseRevision,'revision'=>$revision->revision,
            ]);
            return $this->decisions->viewPolicy($organizationId,$policyId,$revision->revision)
                ?? throw new InvalidArgumentException('Growth qualification policy revision could not be read back.');
        });
    }

    public function activateQualificationPolicy(
        string $organizationId,int $actorId,string $correlationId,string $policyId,int $revision,string $idempotencyKey
    ): array {
        $policyId=$this->bounded(trim($policyId),'policyId',80);
        if($revision<1)throw new InvalidArgumentException('Growth qualification policy revision must be positive.');
        $fingerprint=$this->fingerprint(['policy_id'=>$policyId,'revision'=>$revision]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$policyId,$revision,$idempotencyKey,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'activate_qualification_policy',$idempotencyKey,$fingerprint)){
                return ($this->decisions->viewPolicy($organizationId,$policyId,$revision)
                    ?? throw new InvalidArgumentException('Growth qualification policy activation receipt exists but policy was not found.'))
                    + ['replayed'=>true];
            }
            $policy=$this->decisions->lockPolicy($organizationId,$policyId,$revision);
            $policy->activate();
            $this->decisions->archiveOtherActivePolicyRevisions($organizationId,$policyId,$revision,$actorId);
            $this->decisions->updatePolicy($policy,$actorId);
            $this->publish(GrowthEventType::QUALIFICATION_POLICY_ACTIVATED,$organizationId,'growth_qualification_policy',$policyId,[
                'revision'=>$revision,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.qualification_policy.activate','growth_qualification_policy',$policyId,$idempotencyKey,[
                'revision'=>$revision,
            ]);
            return $this->decisions->viewPolicy($organizationId,$policyId,$revision)
                ?? throw new InvalidArgumentException('Activated Growth qualification policy could not be read back.');
        });
    }

    public function evaluateCandidate(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $policyId,int $revision,string $idempotencyKey
    ): array {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $policyId=$this->bounded(trim($policyId),'policyId',80);
        if($revision<1)throw new InvalidArgumentException('Growth qualification policy revision must be positive.');
        $fingerprint=$this->fingerprint(['candidate_id'=>$candidateId,'policy_id'=>$policyId,'revision'=>$revision]);
        $evaluationId='GQEV-'.$this->stableId($organizationId.':qualification_evaluation:'.$idempotencyKey);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$policyId,$revision,$idempotencyKey,$fingerprint,$evaluationId
        ):array{
            if(!$this->receipts->claim($organizationId,'evaluate_candidate',$idempotencyKey,$fingerprint)){
                return ($this->decisions->viewEvaluation($organizationId,$evaluationId)
                    ?? throw new InvalidArgumentException('Growth qualification evaluation receipt exists but evaluation was not found.'))
                    + ['replayed'=>true];
            }

            $candidate=$this->growth->lockCandidate($organizationId,$candidateId);
            if($candidate->status()!==OpportunityCandidateStatus::Scored){
                throw new InvalidArgumentException('Growth policy evaluation requires a scored candidate.');
            }
            $rationale=$candidate->rationale()??throw new InvalidArgumentException('Growth scored candidate is missing rationale.');
            $score=$candidate->score()??throw new InvalidArgumentException('Growth scored candidate is missing score.');

            $policy=$this->decisions->lockPolicy($organizationId,$policyId,$revision);
            if($policy->status()!==QualificationPolicyStatus::Active){
                throw new InvalidArgumentException('Growth policy evaluation requires an active qualification policy.');
            }

            $evaluation=$this->evaluator->evaluate($candidateId,$policy,$rationale,$score,$this->now());
            $decisionReason=$evaluation->reason;
            if($evaluation->failedCriteria!==[])$decisionReason.=' ['.implode(', ',$evaluation->failedCriteria).']';

            $lifecycleEvent=match($evaluation->outcome){
                QualificationOutcome::Qualified => GrowthEventType::CANDIDATE_QUALIFIED,
                QualificationOutcome::Monitor => GrowthEventType::CANDIDATE_MONITORING_STARTED,
                QualificationOutcome::Disqualified => GrowthEventType::CANDIDATE_DISQUALIFIED,
            };
            switch($evaluation->outcome){
                case QualificationOutcome::Qualified:
                    $candidate->qualify($decisionReason);
                    break;
                case QualificationOutcome::Monitor:
                    $candidate->monitor($decisionReason);
                    break;
                case QualificationOutcome::Disqualified:
                    $candidate->disqualify($decisionReason);
                    break;
            }

            $this->growth->updateCandidate($candidate,$actorId);
            $this->decisions->createEvaluation($evaluationId,$organizationId,$evaluation,$actorId);

            $payload=[
                'evaluation_id'=>$evaluationId,'policy_id'=>$policyId,'policy_revision'=>$revision,
                'outcome'=>$evaluation->outcome->value,'failed_criteria'=>$evaluation->failedCriteria,
                'model_version'=>QualificationEvaluation::MODEL_VERSION,
            ];
            $this->publish(GrowthEventType::CANDIDATE_EVALUATED,$organizationId,'growth_candidate',$candidateId,$payload,$actorId,$correlationId);
            $this->publish($lifecycleEvent,$organizationId,'growth_candidate',$candidateId,$payload,$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.candidate.evaluate','growth_candidate',$candidateId,$idempotencyKey,$payload);

            return $this->decisions->viewEvaluation($organizationId,$evaluationId)
                ?? throw new InvalidArgumentException('Growth qualification evaluation could not be read back.');
        });
    }

    public function decisionBrief(string $organizationId,string $candidateId): array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $candidate=$this->growth->viewCandidate($organizationId,$candidateId)
            ?? throw new InvalidArgumentException('Growth candidate was not found.');
        return [
            'candidate'=>$candidate,
            'latest_evaluation'=>$this->decisions->latestEvaluation($organizationId,$candidateId),
        ];
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key,int $limit): string
    {
        return $this->bounded(trim((string)($input[$key]??'')),$key,$limit);
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
            bin2hex(random_bytes(16)),$organizationId,'growth.decision','USER',(string)$actorId,
            $subjectType,$subjectId,null,['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],
            $correlationId,$this->now(),
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
        return hash('sha256',(string)json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function now(): DateTimeImmutable { return new DateTimeImmutable('now',new DateTimeZone('UTC')); }
}
