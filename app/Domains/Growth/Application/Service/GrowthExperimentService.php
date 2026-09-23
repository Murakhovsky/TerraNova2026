<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthExperimentBoundary;
use Domains\Growth\Application\Contract\GrowthExperimentRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\GrowthExperiment;
use Domains\Growth\Domain\GrowthExperimentAssignment;
use Domains\Growth\Domain\GrowthExperimentAssignmentSource;
use Domains\Growth\Domain\GrowthExperimentDimension;
use Domains\Growth\Domain\GrowthExperimentStatus;
use Domains\Growth\Domain\GrowthExperimentVariant;
use Domains\Growth\Domain\GrowthOutcomeType;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthExperimentService implements GrowthExperimentBoundary
{
    public function __construct(
        private GrowthExperimentRepositoryInterface $experiments,
        private GrowthRepositoryInterface $growth,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function createExperiment(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ):array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $name=$this->required($input,'name',191);
        $hypothesis=$this->required($input,'hypothesis',2000);
        $dimension=GrowthExperimentDimension::tryFrom(strtolower($this->required($input,'dimension',40)))
            ?? throw new InvalidArgumentException('Growth experiment dimension is invalid.');
        $primary=GrowthOutcomeType::tryFrom(strtolower($this->required($input,'primary_outcome',80)))
            ?? throw new InvalidArgumentException('Growth experiment primary_outcome is invalid.');
        $variants=$this->variants($input['variants']??null);
        $experimentId='GEXP-'.$this->stableId($organizationId.':experiment:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'name'=>$name,'hypothesis'=>$hypothesis,'dimension'=>$dimension->value,
            'primary_outcome'=>$primary->value,
            'variants'=>array_map(static fn(GrowthExperimentVariant $variant):array=>$variant->toArray(),$variants),
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$experimentId,$name,$hypothesis,$dimension,$primary,$variants,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'create_growth_experiment',$idempotencyKey,$fingerprint)){
                return [
                    'experiment'=>$this->experiments->viewExperiment($organizationId,$experimentId)
                        ?? throw new InvalidArgumentException('Growth experiment receipt exists but Experiment was not found.'),
                    'replayed'=>true,
                ];
            }

            $experiment=new GrowthExperiment(
                $experimentId,OrganizationId::fromString($organizationId),$name,$hypothesis,$dimension,$primary,$variants,$this->now(),
            );
            $this->experiments->createExperiment($experiment,$actorId);
            $this->publish(GrowthEventType::EXPERIMENT_DRAFTED,$organizationId,'growth_experiment',$experimentId,[
                'dimension'=>$dimension->value,'primary_outcome'=>$primary->value,
                'variant_keys'=>array_map(static fn(GrowthExperimentVariant $variant):string=>$variant->key,$variants),
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.experiment.drafted','growth_experiment',$experimentId,
                $idempotencyKey,['dimension'=>$dimension->value,'primary_outcome'=>$primary->value],
            );
            return [
                'experiment'=>$this->experiments->viewExperiment($organizationId,$experimentId)
                    ?? throw new InvalidArgumentException('Created Growth experiment could not be read back.'),
            ];
        });
    }

    public function startExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array {
        return $this->transition('start',$organizationId,$actorId,$correlationId,$experimentId,$idempotencyKey);
    }

    public function pauseExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array {
        return $this->transition('pause',$organizationId,$actorId,$correlationId,$experimentId,$idempotencyKey);
    }

    public function resumeExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array {
        return $this->transition('resume',$organizationId,$actorId,$correlationId,$experimentId,$idempotencyKey);
    }

    public function completeExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array {
        return $this->transition('complete',$organizationId,$actorId,$correlationId,$experimentId,$idempotencyKey);
    }

    public function archiveExperiment(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array {
        return $this->transition('archive',$organizationId,$actorId,$correlationId,$experimentId,$idempotencyKey);
    }

    public function assignCandidate(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $candidateId,
        ?string $variantKey,string $idempotencyKey
    ):array {
        $experimentId=$this->bounded(trim($experimentId),'experimentId',80);
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $variantKey=$variantKey===null?null:strtolower($this->bounded(trim($variantKey),'variantKey',64));
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $fingerprint=$this->fingerprint([
            'experiment_id'=>$experimentId,'candidate_id'=>$candidateId,'variant_key'=>$variantKey,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$experimentId,$candidateId,$variantKey,$idempotencyKey,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'assign_growth_experiment_candidate',$idempotencyKey,$fingerprint)){
                $existing=$this->experiments->findAssignment($organizationId,$experimentId,$candidateId)
                    ?? throw new InvalidArgumentException('Growth experiment assignment receipt exists but Assignment was not found.');
                return ['assignment'=>$existing,'replayed'=>true];
            }

            $experiment=$this->experiments->lockExperiment($organizationId,$experimentId);
            if($experiment->status()!==GrowthExperimentStatus::Running){
                throw new InvalidArgumentException('Growth experiment must be running before Candidate assignment.');
            }

            $candidate=$this->growth->viewCandidate($organizationId,$candidateId)
                ?? throw new InvalidArgumentException('Growth Candidate was not found.');
            $candidateStatus=OpportunityCandidateStatus::tryFrom((string)($candidate['status']??''))
                ?? throw new InvalidArgumentException('Growth Candidate status is invalid.');
            if($candidateStatus->isTerminal()){
                throw new InvalidArgumentException('Terminal Growth Candidate cannot be assigned to an experiment.');
            }
            if($this->experiments->candidateHasTerminalOutcome($organizationId,$candidateId)){
                throw new InvalidArgumentException('Growth Candidate already has a terminal outcome and cannot enter a new experiment.');
            }

            $existing=$this->experiments->findAssignment($organizationId,$experimentId,$candidateId);
            if($existing!==null){
                if($variantKey!==null&&($existing['variant_key']??null)!==$variantKey){
                    throw new InvalidArgumentException('Growth Candidate is already assigned to another variant in this experiment.');
                }
                return ['assignment'=>$existing,'replayed'=>true];
            }

            $variant=$variantKey===null?$experiment->chooseVariant($candidateId):$experiment->variant($variantKey);
            $source=$variantKey===null?GrowthExperimentAssignmentSource::Deterministic:GrowthExperimentAssignmentSource::Manual;
            $assignmentId='GEAS-'.$this->stableId($organizationId.':'.$experimentId.':'.$candidateId);
            $assignment=new GrowthExperimentAssignment(
                $assignmentId,OrganizationId::fromString($organizationId),$experimentId,$candidateId,$variant->key,$source,
                $this->candidateContext($candidate),$this->now(),
            );
            $this->experiments->createAssignment($assignment,$actorId);
            $this->publish(GrowthEventType::EXPERIMENT_CANDIDATE_ASSIGNED,$organizationId,'growth_experiment',$experimentId,[
                'assignment_id'=>$assignmentId,'candidate_id'=>$candidateId,'variant_key'=>$variant->key,
                'assignment_source'=>$source->value,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.experiment.candidate_assigned','growth_experiment',$experimentId,
                $idempotencyKey,['assignment_id'=>$assignmentId,'candidate_id'=>$candidateId,'variant_key'=>$variant->key,'source'=>$source->value],
            );
            return [
                'assignment'=>$this->experiments->findAssignment($organizationId,$experimentId,$candidateId)
                    ?? throw new InvalidArgumentException('Created Growth experiment assignment could not be read back.'),
            ];
        });
    }

    public function experimentBrief(string $organizationId,string $experimentId):array
    {
        $experimentId=$this->bounded(trim($experimentId),'experimentId',80);
        return [
            'experiment'=>$this->experiments->viewExperiment($organizationId,$experimentId)
                ?? throw new InvalidArgumentException('Growth experiment was not found.'),
            'assignments'=>$this->experiments->assignments($organizationId,$experimentId,500),
            'attribution'=>$this->experiments->attributionReport($organizationId,$experimentId),
        ];
    }

    public function experiments(string $organizationId,array $filters=[],int $limit=100):array
    {
        return $this->experiments->listExperiments($organizationId,$filters,$limit);
    }

    /** @return array<string,mixed> */
    private function transition(
        string $transition,string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array {
        $experimentId=$this->bounded(trim($experimentId),'experimentId',80);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $fingerprint=$this->fingerprint(['experiment_id'=>$experimentId,'transition'=>$transition]);
        $operation=$transition.'_growth_experiment';

        return $this->transactions->transactional(function()use(
            $transition,$organizationId,$actorId,$correlationId,$experimentId,$idempotencyKey,$fingerprint,$operation
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return [
                    'experiment'=>$this->experiments->viewExperiment($organizationId,$experimentId)
                        ?? throw new InvalidArgumentException('Growth experiment transition receipt exists but Experiment was not found.'),
                    'replayed'=>true,
                ];
            }

            $experiment=$this->experiments->lockExperiment($organizationId,$experimentId);
            [$event,$audit]=match($transition){
                'start'=>[GrowthEventType::EXPERIMENT_STARTED,'growth.experiment.started'],
                'pause'=>[GrowthEventType::EXPERIMENT_PAUSED,'growth.experiment.paused'],
                'resume'=>[GrowthEventType::EXPERIMENT_RESUMED,'growth.experiment.resumed'],
                'complete'=>[GrowthEventType::EXPERIMENT_COMPLETED,'growth.experiment.completed'],
                'archive'=>[GrowthEventType::EXPERIMENT_ARCHIVED,'growth.experiment.archived'],
                default=>throw new InvalidArgumentException('Unknown Growth experiment transition.'),
            };

            match($transition){
                'start'=>$experiment->start($this->now()),
                'pause'=>$experiment->pause(),
                'resume'=>$experiment->resume(),
                'complete'=>$experiment->complete($this->now()),
                'archive'=>$experiment->archive(),
            };
            $this->experiments->updateExperiment($experiment,$actorId);
            $payload=[
                'status'=>$experiment->status()->value,
                'started_at'=>$experiment->startedAt()?->format(DATE_ATOM),
                'ended_at'=>$experiment->endedAt()?->format(DATE_ATOM),
            ];
            $this->publish($event,$organizationId,'growth_experiment',$experimentId,$payload,$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,$audit,'growth_experiment',$experimentId,$idempotencyKey,$payload,
            );
            return [
                'experiment'=>$this->experiments->viewExperiment($organizationId,$experimentId)
                    ?? throw new InvalidArgumentException('Updated Growth experiment could not be read back.'),
                'attribution'=>$this->experiments->attributionReport($organizationId,$experimentId),
            ];
        });
    }

    /** @return list<GrowthExperimentVariant> */
    private function variants(mixed $value):array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Growth experiment variants must be a list.');
        $variants=[];
        foreach($value as $variant){
            if(!is_array($variant)||array_is_list($variant))throw new InvalidArgumentException('Growth experiment variant must be an object.');
            $variants[]=GrowthExperimentVariant::fromArray($variant);
        }
        return $variants;
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function candidateContext(array $candidate):array
    {
        return [
            'candidate_id'=>$candidate['candidate_id']??null,
            'opportunity_type'=>$candidate['opportunity_type']??null,
            'growth_mode'=>$candidate['growth_mode']??null,
            'subject_type'=>$candidate['subject_type']??null,
            'subject_id'=>$candidate['subject_id']??null,
            'target_domain'=>$candidate['target_domain']??null,
            'status'=>$candidate['status']??null,
            'score'=>$candidate['score']??null,
            'expected_value'=>$candidate['expected_value']??null,
            'recommended_play'=>$candidate['recommended_play']??null,
            'recommended_action'=>$candidate['recommended_action']??null,
        ];
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key,int $limit):string
    {
        $value=$input[$key]??null;
        if(!is_string($value))throw new InvalidArgumentException($key.' is required.');
        return $this->bounded(trim($value),$key,$limit);
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,int $actorId,string $correlationId
    ):void {
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
            bin2hex(random_bytes(16)),$organizationId,'growth.experiment','USER',(string)$actorId,
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
        return hash('sha256',(string)json_encode(
            $normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
