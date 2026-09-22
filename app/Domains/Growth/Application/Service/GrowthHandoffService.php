<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthHandoffBoundary;
use Domains\Growth\Application\Contract\GrowthHandoffRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\DTO\HandoffTargetResult;
use Domains\Growth\Application\DTO\OpportunityHandoff;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\HandoffAttemptStatus;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthHandoffService implements GrowthHandoffBoundary
{
    public function __construct(
        private GrowthHandoffTargetRegistry $targets,
        private GrowthHandoffRepositoryInterface $handoffs,
        private GrowthRepositoryInterface $growth,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function dispatch(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $candidateId,
        string $idempotencyKey,
    ): array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $attemptId='GHAT-'.$this->stableId($organizationId.':handoff_attempt:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(['candidate_id'=>$candidateId]);

        $setup=$this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$idempotencyKey,$attemptId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'dispatch_handoff',$idempotencyKey,$fingerprint)){
                $attempt=$this->handoffs->viewAttempt($organizationId,$attemptId)
                    ?? throw new InvalidArgumentException('Growth handoff receipt exists but attempt was not found.');
                if(($attempt['status']??null)===HandoffAttemptStatus::Running->value){
                    $candidate=$this->growth->lockCandidate($organizationId,$candidateId);
                    if($candidate->status()!==OpportunityCandidateStatus::HandoffPending){
                        throw new InvalidArgumentException('Running Growth handoff attempt requires handoff_pending Candidate.');
                    }
                    $package=$attempt['package']??null;
                    if(!is_array($package))throw new InvalidArgumentException('Running Growth handoff attempt lost package snapshot.');
                    $handoff=OpportunityHandoff::fromArray($package);
                    if(
                        $handoff->candidateId!==$candidateId
                        || $handoff->organizationId!==$organizationId
                        || $handoff->targetDomain!==$candidate->targetDomain
                        || $handoff->targetDomain!==(string)($attempt['target_domain']??'')
                    ){
                        throw new InvalidArgumentException('Running Growth handoff package does not match persisted Candidate/attempt identity.');
                    }
                    $this->targets->get($handoff->targetDomain);
                    return ['replay'=>null,'handoff'=>$handoff,'resumed'=>true];
                }

                return [
                    'replay'=>[
                        'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                            ?? throw new InvalidArgumentException('Growth handoff replay Candidate was not found.'),
                        'attempt'=>$attempt,
                        'replayed'=>true,
                    ],
                ];
            }

            $candidate=$this->growth->lockCandidate($organizationId,$candidateId);
            $handoff=OpportunityHandoff::fromCandidate($candidate);
            $this->targets->get($handoff->targetDomain);
            if($this->handoffs->hasRunningAttempt($organizationId,$candidateId)){
                throw new InvalidArgumentException('Growth candidate already has a running handoff attempt.');
            }

            $packageFingerprint=$this->fingerprint($handoff->toArray());
            $this->handoffs->createAttempt(
                $organizationId,$attemptId,$handoff,$packageFingerprint,$actorId,
            );
            $candidate->startHandoffDispatch();
            $this->growth->updateCandidate($candidate,$actorId);

            $this->publish(GrowthEventType::HANDOFF_DISPATCH_STARTED,$organizationId,'growth_candidate',$candidateId,[
                'attempt_id'=>$attemptId,'target_domain'=>$handoff->targetDomain,
                'payload_fingerprint'=>$packageFingerprint,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.handoff.dispatch_started',
                'growth_candidate',$candidateId,$idempotencyKey,
                ['attempt_id'=>$attemptId,'target_domain'=>$handoff->targetDomain],
            );

            return ['replay'=>null,'handoff'=>$handoff,'resumed'=>false];
        });

        if(is_array($setup['replay']??null))return $setup['replay'];
        $handoff=$setup['handoff']??null;
        if(!$handoff instanceof OpportunityHandoff)throw new InvalidArgumentException('Growth handoff setup lost package.');
        $target=$this->targets->get($handoff->targetDomain);
        $targetIdempotencyKey='growth-handoff-'.$this->stableId($organizationId.':candidate:'.$candidateId);

        try{
            $result=$target->accept($handoff,$correlationId,$targetIdempotencyKey);
        }catch(Throwable $error){
            return $this->recoverFailure(
                $organizationId,$actorId,$correlationId,$candidateId,$attemptId,$idempotencyKey,$handoff,$error,
            );
        }

        return $this->resolveTargetResult(
            $organizationId,$actorId,$correlationId,$candidateId,$attemptId,$idempotencyKey,$handoff,$result,
        );
    }

    public function handoffBrief(string $organizationId,string $candidateId): array
    {
        $candidateId=$this->bounded(trim($candidateId),'candidateId',80);
        return [
            'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                ?? throw new InvalidArgumentException('Growth candidate was not found.'),
            'latest_attempt'=>$this->handoffs->latestAttempt($organizationId,$candidateId),
        ];
    }

    public function targets(): array
    {
        return $this->targets->domains();
    }

    /** @return array<string,mixed> */
    private function recoverFailure(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $attemptId,
        string $idempotencyKey,OpportunityHandoff $handoff,Throwable $error
    ): array {
        $summary=mb_substr(trim($error->getMessage())!==''?get_class($error).': '.$error->getMessage():get_class($error),0,2000);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$attemptId,$idempotencyKey,$handoff,$summary
        ):array{
            $candidate=$this->growth->lockCandidate($organizationId,$candidateId);
            $attempt=$this->handoffs->viewAttempt($organizationId,$attemptId)
                ?? throw new InvalidArgumentException('Growth handoff failure attempt was not found.');
            if(($attempt['status']??null)!==HandoffAttemptStatus::Running->value){
                return [
                    'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                        ?? throw new InvalidArgumentException('Concurrent Growth handoff Candidate was not found.'),
                    'attempt'=>$attempt,
                    'replayed'=>true,
                ];
            }
            if($candidate->status()!==OpportunityCandidateStatus::HandoffPending){
                throw new InvalidArgumentException('Failed Growth handoff attempt requires handoff_pending Candidate.');
            }
            $this->handoffs->failAttempt($organizationId,$attemptId,$summary);
            $candidate->markHandoffDispatchFailed();
            $this->growth->updateCandidate($candidate,$actorId);

            $this->publish(GrowthEventType::HANDOFF_DISPATCH_FAILED,$organizationId,'growth_candidate',$candidateId,[
                'attempt_id'=>$attemptId,'target_domain'=>$handoff->targetDomain,'error'=>$summary,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.handoff.dispatch_failed',
                'growth_candidate',$candidateId,$idempotencyKey,
                ['attempt_id'=>$attemptId,'target_domain'=>$handoff->targetDomain,'error'=>$summary],
            );
            return [
                'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                    ?? throw new InvalidArgumentException('Recovered Growth candidate could not be read back.'),
                'attempt'=>$this->handoffs->viewAttempt($organizationId,$attemptId)
                    ?? throw new InvalidArgumentException('Failed Growth handoff attempt could not be read back.'),
            ];
        });
    }

    /** @return array<string,mixed> */
    private function resolveTargetResult(
        string $organizationId,int $actorId,string $correlationId,string $candidateId,string $attemptId,
        string $idempotencyKey,OpportunityHandoff $handoff,HandoffTargetResult $result
    ): array {
        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$candidateId,$attemptId,$idempotencyKey,$handoff,$result
        ):array{
            $candidate=$this->growth->lockCandidate($organizationId,$candidateId);
            $attempt=$this->handoffs->viewAttempt($organizationId,$attemptId)
                ?? throw new InvalidArgumentException('Growth handoff resolution attempt was not found.');
            if(($attempt['status']??null)!==HandoffAttemptStatus::Running->value){
                return [
                    'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                        ?? throw new InvalidArgumentException('Concurrent Growth handoff Candidate was not found.'),
                    'attempt'=>$attempt,
                    'replayed'=>true,
                ];
            }
            if($candidate->status()!==OpportunityCandidateStatus::HandoffPending){
                throw new InvalidArgumentException('Resolved Growth handoff requires handoff_pending Candidate.');
            }

            if($result->accepted){
                $candidate->markHandedOff();
                $this->handoffs->acceptAttempt(
                    $organizationId,$attemptId,(string)$result->referenceType,(string)$result->referenceId,$result->reason,
                );
                $eventType=GrowthEventType::HANDOFF_ACCEPTED;
                $auditAction='growth.handoff.accepted';
            }else{
                $candidate->markRejectedByTargetDomain($result->reason);
                $this->handoffs->rejectAttempt($organizationId,$attemptId,$result->reason);
                $eventType=GrowthEventType::HANDOFF_REJECTED;
                $auditAction='growth.handoff.rejected';
            }

            $this->growth->updateCandidate($candidate,$actorId);
            $payload=[
                'attempt_id'=>$attemptId,'target_domain'=>$handoff->targetDomain,
                'accepted'=>$result->accepted,'reference_type'=>$result->referenceType,
                'reference_id'=>$result->referenceId,'reason'=>$result->reason,
            ];
            $this->publish($eventType,$organizationId,'growth_candidate',$candidateId,$payload,$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,$auditAction,'growth_candidate',$candidateId,$idempotencyKey,$payload,
            );

            return [
                'candidate'=>$this->growth->viewCandidate($organizationId,$candidateId)
                    ?? throw new InvalidArgumentException('Resolved Growth candidate could not be read back.'),
                'attempt'=>$this->handoffs->viewAttempt($organizationId,$attemptId)
                    ?? throw new InvalidArgumentException('Resolved Growth handoff attempt could not be read back.'),
            ];
        });
    }

    private function bounded(string $value,string $field,int $limit): string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,string $organizationId,string $aggregateType,string $aggregateId,
        array $payload,int $actorId,string $correlationId
    ): void {
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
            bin2hex(random_bytes(16)),$organizationId,'growth.handoff','USER',(string)$actorId,
            $subjectType,$subjectId,null,
            ['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],
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
