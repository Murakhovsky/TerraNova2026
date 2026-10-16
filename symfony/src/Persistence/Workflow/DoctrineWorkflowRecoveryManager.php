<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantPermissions;
use LogicException;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Contract\WorkflowRecoveryInterface;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Model\WorkflowRecoveryAssessment;
use Platform\Workflow\Model\WorkflowRecoveryDisposition;
use Platform\Workflow\Model\WorkflowRecoveryPetition;
use Platform\Workflow\Model\WorkflowRecoveryResolution;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;
use Throwable;

/**
 * Explicit two-person CAS recovery of quarantined Workflow execution.
 * NO external retry, dispatch, worker claim extension or Action status rewrite.
 *
 * request -> independent manager approval -> reconcile(request ID) are distinct,
 * tenant-fenced operations. Revoked/changed receipts stop the apply transaction.
 */
final readonly class DoctrineWorkflowRecoveryManager implements WorkflowRecoveryInterface
{
    public function __construct(
        private Connection $db,
        private DoctrineWorkflowRecoveryInspector $inspector,
        private WorkflowReceiptVerifierInterface $receipts,
        private WorkflowNonActionReceiptEvidenceInterface $nonActions,
    ) {}

    public function inspect(WorkflowRunCommand $command): WorkflowRecoveryAssessment
    {
        return $this->inspector->inspect($command);
    }

    /** @return list<array<string,mixed>> */
    public function expiredClaims(WorkflowRunCommand $command, int $limit = 50): array
    {
        self::authorize($command);
        $limit = max(1, min(100, $limit));
        return $this->db->fetchAllAssociative(
            'SELECT s.run_id,s.step_id,s.state AS step_state,s.side_effect_level,
                    s.revision AS step_revision,s.claim_expires_at,
                    r.state AS run_state,r.revision AS run_revision,
                    a.status AS canonical_action_status,
                    (SELECT d.request_id FROM cos_workflow_recovery_decisions d
                     WHERE d.organization_id=s.organization_id AND d.run_id=s.run_id
                       AND d.step_id=s.step_id AND d.status IN ("REQUESTED","APPROVED")
                     ORDER BY d.requested_at DESC LIMIT 1) AS pending_request_id
             FROM cos_workflow_steps s
             JOIN cos_workflow_runs r ON r.organization_id=s.organization_id AND r.run_id=s.run_id
             LEFT JOIN cos_actions a ON a.organization_id=s.organization_id
               AND a.idempotency_key=CONCAT("wf:",s.idempotency_key)
             WHERE s.organization_id=:org
               AND (s.state="ambiguous" OR (s.state="claimed" AND s.claim_expires_at < NOW(6)))
               AND r.state IN ("running","waiting","ambiguous")
             ORDER BY s.claim_expires_at,s.run_id,s.step_id LIMIT ' . $limit,
            ['org'=>$command->identity->organizationId],
        );
    }

    /** Read-only, tenant-authorized queue of pending and completed reconciliation requests. */
    public function requestsForRun(WorkflowRunCommand $command, int $limit = 50): array
    {
        self::authorize($command);
        $limit=max(1,min(100,$limit));
        return $this->db->fetchAllAssociative(
            'SELECT request_id,step_id,run_revision,step_revision,resolution,
                    receipt_reference,reason,requested_by,approved_by,status,
                    requested_at,approved_at,applied_at
             FROM cos_workflow_recovery_decisions
             WHERE organization_id=:org AND run_id=:run
             ORDER BY requested_at DESC LIMIT '.$limit,
            ['org'=>$command->identity->organizationId,'run'=>$command->identity->runId],
        );
    }

    public function request(WorkflowRecoveryPetition $petition): string
    {
        $command = $petition->command;
        self::authorize($command);
        return $this->db->transactional(function () use ($petition,$command): string {
            [$run,$step] = $this->lockedRunAndStep($command,$petition->stepId);
            self::assertRevisions($run,$step,$command->expectedRevision,$petition->expectedStepRevision);
            self::eligible($run,$step,$petition->resolution,$petition->receiptReference);

            $keys = ['org'=>$command->identity->organizationId,'request'=>$petition->requestId];
            $existing = $this->db->fetchAssociative(
                'SELECT run_id,step_id,run_revision,step_revision,resolution,receipt_reference,
                        reason,requested_by FROM cos_workflow_recovery_decisions
                 WHERE organization_id=:org AND request_id=:request FOR UPDATE',
                $keys,
            );
            if ($existing) {
                if ($existing['run_id'] !== $command->identity->runId
                    || $existing['step_id'] !== $petition->stepId
                    || (int) $existing['run_revision'] !== $command->expectedRevision
                    || (int) $existing['step_revision'] !== $petition->expectedStepRevision
                    || $existing['resolution'] !== $petition->resolution->value
                    || $existing['receipt_reference'] !== $petition->receiptReference
                    || $existing['reason'] !== $petition->reason
                    || $existing['requested_by'] !== $command->actor->userId()->value()) {
                    throw new DomainException('Recovery request ID conflicts with a different decision.');
                }
                return $petition->requestId;
            }
            $this->db->insert('cos_workflow_recovery_decisions', [
                'organization_id'=>$keys['org'],'request_id'=>$petition->requestId,
                'run_id'=>$command->identity->runId,'step_id'=>$petition->stepId,
                'run_revision'=>$command->expectedRevision,
                'step_revision'=>$petition->expectedStepRevision,
                'resolution'=>$petition->resolution->value,
                'receipt_reference'=>$petition->receiptReference,
                'reason'=>$petition->reason,
                'requested_by'=>$command->actor->userId()->value(),
                'status'=>'REQUESTED','requested_at'=>self::now(),
            ]);
            $this->audit($keys['org'],$petition->requestId,$command->identity->runId,
                'REQUESTED',$command->actor->userId()->value(),
                $command->expectedRevision,$petition->expectedStepRevision,
                ['resolution'=>$petition->resolution->value,'reason'=>$petition->reason]);
            return $petition->requestId;
        });
    }

    /**
     * A distinct, authenticated second tenant manager must explicitly approve.
     * This is not a replacement for the Business Plan's original human gate.
     */
    public function approve(WorkflowRunCommand $command, string $requestId): void
    {
        self::authorize($command);
        self::requestId($requestId);
        $this->db->transactional(function () use ($command,$requestId): void {
            [$run,$request,$step] = $this->lockedRequest($command,$requestId);
            self::assertRevisions($run,$step,(int)$request['run_revision'],(int)$request['step_revision']);
            if ($command->expectedRevision !== (int)$request['run_revision']
                || $request['status'] !== 'REQUESTED'
                || $request['requested_by'] === $command->actor->userId()->value()) {
                throw new DomainException('Recovery approval must be fresh and from a different tenant manager.');
            }
            self::eligible($run,$step,WorkflowRecoveryResolution::from($request['resolution']),
                $request['receipt_reference']);
            $changed=$this->db->executeStatement(
                'UPDATE cos_workflow_recovery_decisions
                 SET status="APPROVED",approved_by=:by,approved_at=NOW(6)
                 WHERE organization_id=:org AND request_id=:request AND status="REQUESTED"
                   AND requested_by<>:by',
                ['org'=>$command->identity->organizationId,'request'=>$requestId,
                    'by'=>$command->actor->userId()->value()],
            );
            if ($changed!==1) throw new LogicException('Concurrent or self-approved Recovery request.');
            $this->audit($command->identity->organizationId,$requestId,$command->identity->runId,
                'APPROVED',$command->actor->userId()->value(),
                (int)$request['run_revision'],(int)$request['step_revision'],[]);
        });
    }

    public function reconcile(WorkflowRunCommand $command): WorkflowRecoveryAssessment
    {
        self::authorize($command);
        $requestId=$command->signalReference;
        if (!is_string($requestId)) {
            throw new DomainException('Recovery requires an independently approved request reference.');
        }
        self::requestId($requestId);

        return $this->db->transactional(function () use ($command,$requestId): WorkflowRecoveryAssessment {
            [$run,$request,$step] = $this->lockedRequest($command,$requestId);
            $resolution=WorkflowRecoveryResolution::from((string)$request['resolution']);
            $identity=$command->identity;
            if ($request['status']==='APPLIED') {
                return new WorkflowRecoveryAssessment(
                    $identity,WorkflowRecoveryDisposition::NO_ACTION,'recovery_request_already_applied',
                    in_array($resolution,[WorkflowRecoveryResolution::QUARANTINE,
                        WorkflowRecoveryResolution::CANCEL_UNCERTAIN],true)
                        && $step['side_effect_level']==='external',
                );
            }
            if ($request['status']!=='APPROVED'
                || !is_string($request['approved_by']) || $request['approved_by']===''
                || $request['approved_by']===$request['requested_by']) {
                throw new DomainException('Recovery action lacks independent second-manager approval.');
            }
            self::assertRevisions($run,$step,(int)$request['run_revision'],(int)$request['step_revision']);
            if ($command->expectedRevision!==(int)$run['revision']) {
                throw new LogicException('Stale Run revision on recovery decision.');
            }
            self::eligible($run,$step,$resolution,$request['receipt_reference']);
            if($resolution===WorkflowRecoveryResolution::CANCEL_UNCERTAIN) {
                $otherActive=(int)$this->db->fetchOne(
                    'SELECT COUNT(*) FROM cos_workflow_steps
                     WHERE organization_id=:org AND run_id=:run AND step_id<>:step
                       AND state IN("ambiguous","claimed")',
                    ['org'=>$identity->organizationId,'run'=>$identity->runId,
                     'step'=>$step['step_id']],
                );
                if($otherActive>0) {
                    throw new DomainException('Cannot cancel Run while other uncertain or live steps remain.');
                }
            }
            $graph=$this->checkedDefinition($run,$identity);
            $this->verifyStepBinding($graph,$identity,$step);
            // Quarantine and cancellation may run with corrupt evidence; never
            // claim unknown external effects succeeded. Any form of continuing
            // the Workflow MUST verify every previous completed receipt.
            if (in_array($resolution,[WorkflowRecoveryResolution::VERIFIED_COMPLETE,
                WorkflowRecoveryResolution::RESET_READ_ONLY],true)) {
                $this->verifyCompleted($command,$run,$graph);
            }

            $nextStep=match($resolution) {
                WorkflowRecoveryResolution::QUARANTINE=>'ambiguous',
                WorkflowRecoveryResolution::VERIFIED_COMPLETE=>'completed',
                WorkflowRecoveryResolution::RESET_READ_ONLY=>'pending',
                WorkflowRecoveryResolution::CANCEL_UNCERTAIN=>'cancelled',
            };
            if ($resolution===WorkflowRecoveryResolution::VERIFIED_COMPLETE) {
                $this->verifyReceipt($command,$run,$step,$graph,(string)$request['receipt_reference']);
            }
            if ($resolution===WorkflowRecoveryResolution::RESET_READ_ONLY) {
                $definition=array_values(array_filter($graph,static fn($x):bool=>
                    is_array($x) && ($x['id']??null)===$step['step_id']));
                if(count($definition)!==1
                    || !is_string($definition[0]['execution_binding']??null)
                    || !str_starts_with($definition[0]['execution_binding'],'read:')) {
                    throw new DomainException('Read-only reset requires a pinned read-only execution binding.');
                }
                $this->assertNoExternalAction($identity,(string)$step['idempotency_key']);
            }

            $keys=['org'=>$identity->organizationId,'run'=>$identity->runId,'step'=>$step['step_id']];
            $changed=$this->db->executeStatement(
                'UPDATE cos_workflow_steps SET state=:next,revision=revision+1,
                    result_reference=:receipt,claim_token=NULL,claimed_by=NULL,
                    claim_expires_at=NULL,updated_at=NOW(6)
                 WHERE organization_id=:org AND run_id=:run AND step_id=:step
                   AND revision=:revision AND state=:old',
                ['next'=>$nextStep,'receipt'=>$resolution===WorkflowRecoveryResolution::VERIFIED_COMPLETE
                    ? $request['receipt_reference'] : null,
                    ...$keys,'revision'=>(int)$step['revision'],'old'=>$step['state']],
            );
            if($changed!==1) throw new LogicException('Workflow recovery Step CAS conflict.');

            $others=$this->db->fetchAllAssociative(
                'SELECT step_id,state FROM cos_workflow_steps
                 WHERE organization_id=:org AND run_id=:run',
                ['org'=>$keys['org'],'run'=>$keys['run']],
            );
            $blocking=array_filter($others,static fn(array $s):bool=>
                in_array($s['state'],['claimed','ambiguous','failed'],true));
            $nextRun=match($resolution) {
                WorkflowRecoveryResolution::QUARANTINE=>'ambiguous',
                WorkflowRecoveryResolution::CANCEL_UNCERTAIN=>'cancelled',
                default=>($run['state']==='ambiguous' && $blocking===[])
                    ? 'waiting' : $run['state'],
            };
            $changed=$this->db->executeStatement(
                'UPDATE cos_workflow_runs SET state=:next,revision=revision+1,updated_at=NOW(6)
                 WHERE organization_id=:org AND run_id=:run AND revision=:revision AND state=:old',
                ['next'=>$nextRun,'org'=>$keys['org'],'run'=>$keys['run'],
                    'revision'=>(int)$run['revision'],'old'=>$run['state']],
            );
            if($changed!==1)throw new LogicException('Workflow recovery Run CAS conflict.');

            $changed=$this->db->executeStatement(
                'UPDATE cos_workflow_recovery_decisions SET status="APPLIED",applied_at=NOW(6)
                 WHERE organization_id=:org AND request_id=:request AND status="APPROVED"',
                ['org'=>$keys['org'],'request'=>$requestId],
            );
            if($changed!==1)throw new LogicException('Workflow recovery decision CAS conflict.');
            $this->audit($keys['org'],$requestId,$keys['run'],'APPLIED',
                $command->actor->userId()->value(),(int)$run['revision']+1,
                (int)$step['revision']+1,['run_state'=>$nextRun,'step_state'=>$nextStep,
                    'outcome_unknown'=>$resolution!==WorkflowRecoveryResolution::VERIFIED_COMPLETE
                        && $step['side_effect_level']==='external']);

            return new WorkflowRecoveryAssessment($identity,
                match($resolution){
                    WorkflowRecoveryResolution::QUARANTINE=>WorkflowRecoveryDisposition::MANUAL_REQUIRED,
                    WorkflowRecoveryResolution::VERIFIED_COMPLETE=>WorkflowRecoveryDisposition::RECONCILE_ONLY,
                    WorkflowRecoveryResolution::RESET_READ_ONLY=>WorkflowRecoveryDisposition::SAFE_PRE_EFFECT_RETRY,
                    WorkflowRecoveryResolution::CANCEL_UNCERTAIN=>WorkflowRecoveryDisposition::NO_ACTION,
                },
                'approved_'. $resolution->value,
                $step['side_effect_level']==='external'
                    && $resolution!==WorkflowRecoveryResolution::VERIFIED_COMPLETE);
        });
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function lockedRunAndStep(WorkflowRunCommand $command,string $stepId): array
    {
        $keys=['org'=>$command->identity->organizationId,'run'=>$command->identity->runId];
        $run=$this->db->fetchAssociative(
            'SELECT * FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run FOR UPDATE',$keys);
        if(!$run)throw new DomainException('Unknown tenant-owned Workflow Run.');
        $step=$this->db->fetchAssociative(
            'SELECT *, CASE WHEN claim_expires_at IS NOT NULL AND claim_expires_at <= NOW(6)
                       THEN 1 ELSE 0 END AS lease_expired
             FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run AND step_id=:step FOR UPDATE',
            [...$keys,'step'=>$stepId]);
        if(!$step)throw new DomainException('Recovery Step missing in tenant.');
        return [$run,$step];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>} */
    private function lockedRequest(WorkflowRunCommand $command,string $requestId): array
    {
        $keys=['org'=>$command->identity->organizationId,'run'=>$command->identity->runId];
        $run=$this->db->fetchAssociative(
            'SELECT * FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run FOR UPDATE',$keys);
        if(!$run)throw new DomainException('Unknown tenant-owned recovery Run.');
        $request=$this->db->fetchAssociative(
            'SELECT * FROM cos_workflow_recovery_decisions
             WHERE organization_id=:org AND run_id=:run AND request_id=:request FOR UPDATE',
            [...$keys,'request'=>$requestId]);
        if(!$request)throw new DomainException('Recovery request not found in tenant Run.');
        $step=$this->db->fetchAssociative(
            'SELECT *, CASE WHEN claim_expires_at IS NOT NULL AND claim_expires_at <= NOW(6)
                       THEN 1 ELSE 0 END AS lease_expired
             FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run AND step_id=:step FOR UPDATE',
            [...$keys,'step'=>$request['step_id']]);
        if(!$step)throw new DomainException('Recovery Step disappeared.');
        return [$run,$request,$step];
    }

    private static function eligible(array $run,array $step,WorkflowRecoveryResolution $mode,?string $receipt): void
    {
        if(!in_array($run['state'],['running','waiting','ambiguous'],true)
            || !in_array($step['state'],['claimed','ambiguous','waiting'],true)) {
            throw new DomainException('Only in-flight, expired, waiting or quarantined Workflow can be recovered.');
        }
        if($step['state']==='claimed'
            && (int)($step['lease_expired']??0)!==1) {
            throw new DomainException('Cannot reconcile an actively leased Workflow worker.');
        }
        if($mode===WorkflowRecoveryResolution::RESET_READ_ONLY
            && ($step['side_effect_level']!=='none'
                || $step['result_reference']!==null)) {
            throw new DomainException('Read-only reset may not replay an external or evidenced Step.');
        }
        if($mode===WorkflowRecoveryResolution::VERIFIED_COMPLETE
            && ($receipt===null || $receipt==='')) {
            throw new DomainException('Completion needs an independent canonical receipt.');
        }
        if(in_array($mode,[WorkflowRecoveryResolution::QUARANTINE,
            WorkflowRecoveryResolution::CANCEL_UNCERTAIN],true)
            && $step['state']==='waiting'
            && $step['side_effect_level']==='none') {
            throw new DomainException('No external uncertainty to quarantine in a waiting read-only Step.');
        }
    }

    private static function authorize(WorkflowRunCommand $command): void
    {
        if(!$command->actor->isManager() || !$command->actor->allows(TenantPermissions::MANAGE)
            || $command->actor->organizationId()->value()!==$command->identity->organizationId) {
            throw new DomainException('Recovery requires authenticated tenant manager.');
        }
    }

    private static function assertRevisions(array $run,array $step,int $runRevision,int $stepRevision): void
    {
        if((int)$run['revision']!==$runRevision || (int)$step['revision']!==$stepRevision) {
            throw new LogicException('Workflow recovery decision has stale Run or Step revision.');
        }
    }

    /** @return list<array<string,mixed>> */
    private function checkedDefinition(array $run,WorkflowRunIdentity $identity): array
    {
        $graph=json_decode((string)$run['definition_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($graph) || !array_is_list($graph)
            || !hash_equals((string)$run['definition_digest'],
                WorkflowDefinitionFingerprint::digest($graph))) {
            throw new DomainException('Immutable approved Workflow graph cannot be authenticated.');
        }
        return $graph;
    }

    /** @param list<array<string,mixed>> $graph */
    private function verifyStepBinding(array $graph,WorkflowRunIdentity $identity,array $row): void
    {
        $matches=array_values(array_filter($graph,static fn($s):bool=>is_array($s)
            && ($s['id']??null)===$row['step_id']));
        if(count($matches)!==1
            || ($matches[0]['capability_id']??null)!==$row['capability_id']
            || ($matches[0]['capability_version']??null)!==$row['capability_version']
            || ($matches[0]['side_effect_level']??null)!==$row['side_effect_level']
            || !hash_equals(hash('sha256',$identity->organizationId."\0".$identity->runId
                ."\0".$row['step_id']),(string)$row['idempotency_key'])) {
            throw new DomainException('Recovery Step differs from immutable DAG binding.');
        }
    }

    /** @param list<array<string,mixed>> $graph */
    private function verifyReceipt(
        WorkflowRunCommand $command,array $run,array $step,array $graph,string $reference,
    ): void {
        $identity=$command->identity;
        if($step['side_effect_level']==='external') {
            if((int)$step['attempts']!==1) {
                throw new DomainException('Recovered external Step must have exactly one durable claim attempt.');
            }
            if(!preg_match('/^action:[a-f0-9]{32}$/D',$reference)) {
                throw new DomainException('External recovery cannot accept non-canonical Action proof.');
            }
            $attestation=$this->receipts->attest(new WorkflowReceiptRequest(
                $identity,$command->actor,(string)$step['step_id'],$reference,(string)$run['input_digest'],
            ));
            if(!$attestation->verified || $attestation->identity!=$identity
                || $attestation->stepId!==$step['step_id']
                || $attestation->evidenceReference!==$reference
                || $attestation->correlationId!==$run['correlation_id']
                || $attestation->provider!=='canonical-action') {
                throw new DomainException('External effect requires independently attested canonical Action.');
            }
            return;
        }
        if($step['side_effect_level']!=='none')throw new DomainException('Unsupported side-effect class.');
        $matches=array_values(array_filter($graph,static fn($s):bool=>is_array($s)
            && ($s['id']??null)===$step['step_id']));
        if(count($matches)!==1)throw new DomainException('Missing pinned non-Action evidence.');
        $this->nonActions->assertVerified(
            $identity,(string)$step['step_id'],$reference,(string)$run['input_digest'],$matches[0],
        );
    }

    /** @param list<array<string,mixed>> $graph */
    private function verifyCompleted(WorkflowRunCommand $command,array $run,array $graph): void
    {
        $steps=$this->db->fetchAllAssociative(
            'SELECT * FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run ORDER BY ordinal_no FOR UPDATE',
            ['org'=>$command->identity->organizationId,'run'=>$command->identity->runId],
        );
        if(count($steps)!==count($graph))throw new DomainException('Recovery DAG Step count differs from Plan.');
        foreach($steps as $step) {
            $this->verifyStepBinding($graph,$command->identity,$step);
            if($step['state']!=='completed')continue;
            if(!is_string($step['result_reference']) || $step['result_reference']==='') {
                throw new DomainException('Prior completed Step lacks a receipt.');
            }
            $this->verifyReceipt($command,$run,$step,$graph,$step['result_reference']);
        }
    }

    private function assertNoExternalAction(WorkflowRunIdentity $identity,string $key): void
    {
        $count=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM cos_actions WHERE organization_id=:org AND idempotency_key=:key',
            ['org'=>$identity->organizationId,'key'=>'wf:'.$key],
        );
        if($count!==0)throw new DomainException('Read-only reset blocked by a potentially submitted external Action.');
    }

    private function audit(
        string $org,string $requestId,string $runId,string $event,string $actor,
        int $runRevision,int $stepRevision,array $detail,
    ): void {
        $this->db->insert('cos_workflow_recovery_events',[
            'organization_id'=>$org,'request_id'=>$requestId,'run_id'=>$runId,
            'event_type'=>$event,'actor_id'=>$actor,
            'run_revision'=>$runRevision,'step_revision'=>$stepRevision,
            'detail_json'=>json_encode($detail,JSON_THROW_ON_ERROR),
            'created_at'=>self::now(),
        ]);
    }

    private static function requestId(string $id): void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Recovery request ID invalid.');
    }
    private static function now(): string
    {
        return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
