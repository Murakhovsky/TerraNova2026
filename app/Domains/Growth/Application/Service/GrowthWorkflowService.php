<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\DTO\OpportunityHandoff;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\GrowthMode;
use Domains\Growth\Domain\OpportunityCandidate;
use Domains\Growth\Domain\OpportunityRationale;
use Domains\Growth\Domain\OpportunityScore;
use Domains\Growth\Domain\OpportunityType;
use Domains\Growth\Domain\ScoreDimension;
use Domains\Growth\Domain\Signal;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthWorkflowService implements GrowthApplicationBoundary
{
    public function __construct(
        private GrowthRepositoryInterface $growth,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function detectSignal(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input): array
    {
        $subjectType=$this->required($input,'subject_type',80);
        $subjectId=$this->required($input,'subject_id',191);
        $signalType=$this->required($input,'signal_type',120);
        $sourceReference=$this->required($input,'source_reference',500);
        $facts=$this->facts($input['facts']??null);
        $confidence=$this->confidence($input['confidence']??null,'confidence');
        $occurredAt=$this->date($input['occurred_at']??null,'occurred_at');
        $detectedAt=$this->now();
        $signalId='GSIG-'.$this->stableId($organizationId.':signal:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'subject_type'=>$subjectType,'subject_id'=>$subjectId,'signal_type'=>$signalType,
            'source_reference'=>$sourceReference,'facts'=>$facts,'confidence'=>$confidence,
            'occurred_at'=>$occurredAt->format(DATE_ATOM),
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$subjectType,$subjectId,$signalType,
            $sourceReference,$facts,$confidence,$occurredAt,$detectedAt,$signalId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'detect_signal',$idempotencyKey,$fingerprint)){
                return ($this->growth->viewSignal($organizationId,$signalId)
                    ?? throw new InvalidArgumentException('Growth signal receipt exists but Signal was not found.'))
                    + ['replayed'=>true];
            }
            $signal=new Signal(
                $signalId,OrganizationId::fromString($organizationId),$subjectType,$subjectId,$signalType,
                $facts,$sourceReference,$confidence,$occurredAt,$detectedAt,
            );
            $this->growth->createSignal($signal,$actorId);
            $this->publish(GrowthEventType::SIGNAL_DETECTED,$organizationId,'growth_signal',$signalId,[
                'subject_type'=>$subjectType,'subject_id'=>$subjectId,'signal_type'=>$signalType,
                'source_reference'=>$sourceReference,'confidence'=>$confidence,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.signal.detect','growth_signal',$signalId,$idempotencyKey);
            return $this->growth->viewSignal($organizationId,$signalId)
                ?? throw new InvalidArgumentException('Created Growth signal could not be read back.');
        });
    }

    public function ingestExternalSignal(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $source,
        string $idempotencyKey,
        array $input,
    ): array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        if($actorId<=0)throw new InvalidArgumentException('Growth external signal actorId must be positive.');
        $source=strtolower(trim($source));
        if(!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/',$source)){
            throw new InvalidArgumentException('Growth external signal source is invalid.');
        }
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $subjectType=$this->required($input,'subject_type',80);
        $subjectId=$this->required($input,'subject_id',191);
        $signalType=$this->required($input,'signal_type',120);
        $sourceReference=$this->required($input,'source_reference',500);
        $facts=$this->facts($input['facts']??null);
        $confidence=$this->confidence($input['confidence']??null,'confidence');
        $occurredAt=$this->date($input['occurred_at']??null,'occurred_at');
        $detectedAt=$this->now();
        $signalId='GSIG-'.$this->stableId($organizationId.':external_signal:'.$source.':'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'source'=>$source,'subject_type'=>$subjectType,'subject_id'=>$subjectId,'signal_type'=>$signalType,
            'source_reference'=>$sourceReference,'facts'=>$facts,'confidence'=>$confidence,
            'occurred_at'=>$occurredAt->format(DATE_ATOM),
        ]);
        $operation='external_signal_'.substr(hash('sha256',$source),0,16);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$source,$idempotencyKey,$subjectType,$subjectId,$signalType,
            $sourceReference,$facts,$confidence,$occurredAt,$detectedAt,$signalId,$fingerprint,$operation
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return ($this->growth->viewSignal($organizationId,$signalId)
                    ?? throw new InvalidArgumentException('Growth external signal receipt exists but Signal was not found.'))
                    + ['replayed'=>true];
            }
            $signal=new Signal(
                $signalId,OrganizationId::fromString($organizationId),$subjectType,$subjectId,$signalType,
                $facts,$sourceReference,$confidence,$occurredAt,$detectedAt,
            );
            $this->growth->createSignal($signal,$actorId);
            $this->publishAs('SYSTEM',GrowthEventType::SIGNAL_DETECTED,$organizationId,'growth_signal',$signalId,[
                'ingress'=>'external_webhook','source'=>$source,'subject_type'=>$subjectType,'subject_id'=>$subjectId,
                'signal_type'=>$signalType,'source_reference'=>$sourceReference,'confidence'=>$confidence,
            ],$actorId,$correlationId);
            $this->appendAuditAs(
                'SYSTEM','growth.external_signal',$organizationId,$actorId,$correlationId,
                'growth.signal.external_ingest','growth_signal',$signalId,$idempotencyKey,
                ['source'=>$source,'source_reference'=>$sourceReference],
            );
            return $this->growth->viewSignal($organizationId,$signalId)
                ?? throw new InvalidArgumentException('Created external Growth signal could not be read back.');
        });
    }

    public function detectCandidate(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input): array
    {
        $type=OpportunityType::tryFrom($this->required($input,'opportunity_type',80))
            ?? throw new InvalidArgumentException('Unknown Growth opportunity_type.');
        $mode=GrowthMode::tryFrom($this->required($input,'growth_mode',40))
            ?? throw new InvalidArgumentException('Unknown Growth growth_mode.');
        $subjectType=$this->required($input,'subject_type',80);
        $subjectId=$this->required($input,'subject_id',191);
        $targetDomain=$this->required($input,'target_domain',80);
        $signalIds=$this->stringList($input['signal_ids']??null,'signal_ids');
        $candidateId='GCND-'.$this->stableId($organizationId.':candidate:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'opportunity_type'=>$type->value,'growth_mode'=>$mode->value,'subject_type'=>$subjectType,
            'subject_id'=>$subjectId,'target_domain'=>$targetDomain,'signal_ids'=>$signalIds,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$type,$mode,$subjectType,$subjectId,
            $targetDomain,$signalIds,$candidateId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'detect_candidate',$idempotencyKey,$fingerprint)){
                return ($this->growth->viewCandidate($organizationId,$candidateId)
                    ?? throw new InvalidArgumentException('Growth candidate receipt exists but Candidate was not found.'))
                    + ['replayed'=>true];
            }
            foreach($signalIds as $signalId){
                if($this->growth->viewSignal($organizationId,$signalId)===null){
                    throw new InvalidArgumentException('Growth candidate references an unknown Signal: '.$signalId);
                }
            }
            $candidate=OpportunityCandidate::detect(
                $candidateId,OrganizationId::fromString($organizationId),$type,$mode,
                $subjectType,$subjectId,$targetDomain,$signalIds,
            );
            $this->growth->createCandidate($candidate,$actorId);
            $this->publish(GrowthEventType::CANDIDATE_DETECTED,$organizationId,'growth_candidate',$candidateId,[
                'opportunity_type'=>$type->value,'growth_mode'=>$mode->value,'subject_type'=>$subjectType,
                'subject_id'=>$subjectId,'target_domain'=>$targetDomain,'signal_ids'=>$signalIds,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.candidate.detect','growth_candidate',$candidateId,$idempotencyKey);
            return $this->growth->viewCandidate($organizationId,$candidateId)
                ?? throw new InvalidArgumentException('Created Growth candidate could not be read back.');
        });
    }

    public function researchCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey,array $input): array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $rationale=new OpportunityRationale(
            whyItMatters:$this->required($input,'why_it_matters',2000),
            problemHypothesis:$this->required($input,'problem_hypothesis',2000),
            whyNow:$this->required($input,'why_now',2000),
            evidenceIds:$this->stringList($input['evidence_ids']??null,'evidence_ids'),
            counterEvidenceIds:$this->optionalStringList($input['counter_evidence_ids']??[],'counter_evidence_ids'),
            assumptions:$this->optionalStringList($input['assumptions']??[],'assumptions'),
            unknowns:$this->optionalStringList($input['unknowns']??[],'unknowns'),
            confidence:$this->confidence($input['confidence']??null,'confidence'),
        );
        return $this->mutateCandidate(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,'research_candidate',
            $rationale->toArray(),static function(OpportunityCandidate $candidate)use($rationale):void{$candidate->markResearched($rationale);},
            GrowthEventType::CANDIDATE_RESEARCHED,'growth.candidate.research',
            ['why_now'=>$rationale->whyNow,'confidence'=>$rationale->confidence],
        );
    }

    public function scoreCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey,array $input): array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $score=new OpportunityScore(
            fit:$this->dimension($input,'fit'),need:$this->dimension($input,'need'),
            timing:$this->dimension($input,'timing'),access:$this->dimension($input,'access'),
            value:$this->dimension($input,'value'),confidence:$this->confidence($input['confidence']??null,'confidence'),
        );
        return $this->mutateCandidate(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,'score_candidate',
            $score->toArray(),static function(OpportunityCandidate $candidate)use($score):void{$candidate->applyScore($score);},
            GrowthEventType::CANDIDATE_SCORED,'growth.candidate.score',['scores'=>$score->toArray()],
        );
    }

    public function qualifyCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $reason,string $idempotencyKey): array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $reason=$this->bounded(trim($reason),'reason',2000);
        return $this->mutateCandidate(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,'qualify_candidate',['reason'=>$reason],
            static function(OpportunityCandidate $candidate)use($reason):void{$candidate->qualify($reason);},
            GrowthEventType::CANDIDATE_QUALIFIED,'growth.candidate.qualify',['reason'=>$reason],
        );
    }

    public function monitorCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $reason,string $idempotencyKey): array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $reason=$this->bounded(trim($reason),'reason',2000);
        return $this->mutateCandidate(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,'monitor_candidate',['reason'=>$reason],
            static function(OpportunityCandidate $candidate)use($reason):void{$candidate->monitor($reason);},
            GrowthEventType::CANDIDATE_MONITORING_STARTED,'growth.candidate.monitor',['reason'=>$reason],
        );
    }

    public function disqualifyCandidate(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $reason,string $idempotencyKey): array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $reason=$this->bounded(trim($reason),'reason',2000);
        return $this->mutateCandidate(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,'disqualify_candidate',['reason'=>$reason],
            static function(OpportunityCandidate $candidate)use($reason):void{$candidate->disqualify($reason);},
            GrowthEventType::CANDIDATE_DISQUALIFIED,'growth.candidate.disqualify',['reason'=>$reason],
        );
    }

    public function prepareHandoff(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey,array $input): array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $expectedValue=$this->required($input,'expected_value',500);
        $recommendedPlay=$this->required($input,'recommended_play',191);
        $recommendedAction=$this->required($input,'recommended_action',1000);
        $fingerprint=$this->fingerprint([
            'candidate_id'=>$candidateId,'expected_value'=>$expectedValue,
            'recommended_play'=>$recommendedPlay,'recommended_action'=>$recommendedAction,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,$expectedValue,
            $recommendedPlay,$recommendedAction,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'prepare_handoff',$idempotencyKey,$fingerprint)){
                $candidate=$this->growth->lockCandidate($organizationId,$candidateId);
                $handoff=OpportunityHandoff::fromCandidate($candidate);
                return [
                    'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                        ?? throw new InvalidArgumentException('Growth handoff receipt exists but Candidate was not found.'),
                    'handoff'=>$handoff->toArray(),
                    'replayed'=>true,
                ];
            }
            $candidate=$this->growth->lockCandidate($organizationId,$candidateId);
            $candidate->prepareHandoff($expectedValue,$recommendedPlay,$recommendedAction);
            $handoff=OpportunityHandoff::fromCandidate($candidate);
            $this->growth->updateCandidate($candidate,$actorId);
            $this->publish(GrowthEventType::HANDOFF_PREPARED,$organizationId,'growth_candidate',$candidateId,[
                'target_domain'=>$candidate->targetDomain,'recommended_play'=>$recommendedPlay,
                'recommended_action'=>$recommendedAction,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.handoff.prepare','growth_candidate',$candidateId,$idempotencyKey,[
                'target_domain'=>$candidate->targetDomain,'recommended_play'=>$recommendedPlay,
            ]);
            return [
                'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                    ?? throw new InvalidArgumentException('Prepared Growth candidate could not be read back.'),
                'handoff'=>$handoff->toArray(),
            ];
        });
    }

    public function viewSignal(string $organizationId,string $signalId): ?array
    {
        return $this->growth->viewSignal($organizationId,$this->bounded(trim($signalId),'signalId',80));
    }

    public function viewCandidate(string $organizationId,string $candidateId): ?array
    {
        return $this->growth->viewCandidate($organizationId,$this->bounded(trim($candidateId),'candidateId',80));
    }

    /** @param array<string,mixed> $fingerprintPayload @param callable(OpportunityCandidate):void $mutation @param array<string,mixed> $eventPayload */
    private function mutateCandidate(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $idempotencyKey,
        string $operation,array $fingerprintPayload,callable $mutation,string $eventType,string $auditAction,array $eventPayload,
    ): array {
        $fingerprint=$this->fingerprint(['candidate_id'=>$candidateId]+$fingerprintPayload);
        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,$operation,$fingerprint,
            $mutation,$eventType,$auditAction,$eventPayload
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return ($this->growth->viewCandidate($organizationId,$candidateId)
                    ?? throw new InvalidArgumentException('Growth mutation receipt exists but Candidate was not found.'))
                    + ['replayed'=>true];
            }
            $candidate=$this->growth->lockCandidate($organizationId,$candidateId);
            $mutation($candidate);
            $this->growth->updateCandidate($candidate,$actorId);
            $this->publish($eventType,$organizationId,'growth_candidate',$candidateId,$eventPayload,$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,$auditAction,'growth_candidate',$candidateId,$idempotencyKey);
            return $this->growth->viewCandidate($organizationId,$candidateId)
                ?? throw new InvalidArgumentException('Mutated Growth candidate could not be read back.');
        });
    }

    /** @param array<string,mixed> $input */
    private function dimension(array $input,string $key): ScoreDimension
    {
        $value=$input[$key]??null;
        if(!is_array($value))throw new InvalidArgumentException($key.' must be an object.');
        $score=$value['score']??null;
        if(!is_int($score))throw new InvalidArgumentException($key.'.score must be an integer.');
        return new ScoreDimension(
            $score,$this->required($value,'reason',2000),
            $this->stringList($value['evidence_ids']??null,$key.'.evidence_ids'),
            $this->required($value,'model_version',120),
        );
    }

    /** @return array<string,scalar|null> */
    private function facts(mixed $value): array
    {
        if(!is_array($value)||$value===[]||array_is_list($value))throw new InvalidArgumentException('facts must be a non-empty object.');
        foreach($value as $key=>$item){
            if(!is_string($key)||$key===''||(!is_scalar($item)&&$item!==null)){
                throw new InvalidArgumentException('Growth signal facts must contain scalar values.');
            }
        }
        return $value;
    }

    private function confidence(mixed $value,string $field): float
    {
        if(!is_int($value)&&!is_float($value))throw new InvalidArgumentException($field.' must be numeric.');
        $value=(float)$value;
        if($value<0.0||$value>1.0)throw new InvalidArgumentException($field.' must be between 0 and 1.');
        return $value;
    }

    private function date(mixed $value,string $field): DateTimeImmutable
    {
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException($field.' is required.');
        try{
            return new DateTimeImmutable($value,new DateTimeZone('UTC'));
        }catch(\Throwable){
            throw new InvalidArgumentException($field.' is invalid.');
        }
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key,int $limit): string
    {
        return $this->bounded(trim((string)($input[$key]??'')),$key,$limit);
    }

    private function bounded(string $value,string $name,int $limit): string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($name.' is invalid.');
        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value,string $field): array
    {
        $values=$this->optionalStringList($value,$field);
        if($values===[])throw new InvalidArgumentException($field.' must contain at least one value.');
        return $values;
    }

    /** @return list<string> */
    private function optionalStringList(mixed $value,string $field): array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException($field.' must be a list.');
        $result=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException($field.' contains an invalid value.');
            $result[trim($item)]=true;
        }
        return array_keys($result);
    }

    /** @param array<string,mixed> $payload */
    private function publish(string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,int $actorId,string $correlationId): void
    {
        $this->publishAs('USER',$type,$organizationId,$aggregateType,$aggregateId,$payload,$actorId,$correlationId);
    }

    /** @param array<string,mixed> $payload */
    private function publishAs(
        string $actorType,string $type,string $organizationId,string $aggregateType,string $aggregateId,
        array $payload,int $actorId,string $correlationId
    ): void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,$actorType,(string)$actorId),$this->now(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,int $actorId,string $correlationId,string $action,string $subjectType,
        string $subjectId,string $idempotencyKey,array $data=[]
    ): void {
        $this->appendAuditAs(
            'USER','growth.mutation',$organizationId,$actorId,$correlationId,$action,
            $subjectType,$subjectId,$idempotencyKey,$data,
        );
    }

    /** @param array<string,mixed> $data */
    private function appendAuditAs(
        string $actorType,string $component,string $organizationId,int $actorId,string $correlationId,string $action,
        string $subjectType,string $subjectId,string $idempotencyKey,array $data=[]
    ): void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,$component,$actorType,(string)$actorId,
            $subjectType,$subjectId,null,['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],
            $correlationId,$this->now(),
        ));
    }

    private function stableId(string $value): string
    {
        return strtoupper(substr(hash('sha256',$value),0,20));
    }

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
        return hash('sha256',(string)json_encode(
            $normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now',new DateTimeZone('UTC'));
    }
}
