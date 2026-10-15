<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Contract\WorkflowKernelExecutionStoreInterface;
use Platform\Workflow\Model\WorkflowRecoveryPetition;
use Platform\Workflow\Model\WorkflowRecoveryResolution;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Tenant-authenticated two-person recovery for the generic Kernel adapter.
 * This service owns only operator decisions; Platform Store owns Run/Step CAS.
 * It never invokes a provider or requeues an Agent/Tool/Action.
 */
final readonly class DoctrineKernelExecutionRecoveryManager
{
    public function __construct(
        private Connection $db,
        private WorkflowKernelExecutionStoreInterface $store,
    ) {}

    public function owns(WorkflowRunIdentity $identity):bool
    {
        $id=$this->db->fetchOne(
            'SELECT definition_id FROM cos_workflow_runs
             WHERE organization_id=:org AND run_id=:run',
            ['org'=>$identity->organizationId,'run'=>$identity->runId],
        );
        return in_array($id,['kernel.agent_execution','kernel.tool_execution'],true);
    }

    public function request(WorkflowRecoveryPetition $petition):string
    {
        $cmd=$petition->command;
        self::authorized($cmd);
        if(!$this->owns($cmd->identity)
            || $petition->stepId!=='execute'
            || $petition->resolution!==WorkflowRecoveryResolution::VERIFIED_COMPLETE) {
            throw new DomainException('Kernel recovery only accepts verifiable native receipt completion.');
        }
        return $this->db->transactional(function()use($petition,$cmd):string{
            [$run,$step]=$this->lockState($cmd->identity);
            self::eligible($run,$step,$cmd->expectedRevision,$petition->expectedStepRevision);
            $data=[
                'organization_id'=>$cmd->identity->organizationId,
                'request_id'=>$petition->requestId,
                'run_id'=>$cmd->identity->runId,'step_id'=>'execute',
                'run_revision'=>$cmd->expectedRevision,
                'step_revision'=>$petition->expectedStepRevision,
                'resolution'=>'verified_complete',
                'receipt_reference'=>$petition->receiptReference,
                'reason'=>$petition->reason,
                'requested_by'=>$cmd->actor->userId()->value(),
                'status'=>'REQUESTED','requested_at'=>gmdate('Y-m-d H:i:s.u'),
            ];
            $existing=$this->db->fetchAssociative(
                'SELECT run_id,step_id,run_revision,step_revision,resolution,
                        receipt_reference,requested_by,status
                 FROM cos_workflow_recovery_decisions
                 WHERE organization_id=:org AND request_id=:request FOR UPDATE',
                ['org'=>$data['organization_id'],'request'=>$petition->requestId],
            );
            if($existing) {
                if($existing['run_id']!==$data['run_id'] || $existing['step_id']!=='execute'
                    || (int)$existing['run_revision']!==$data['run_revision']
                    || (int)$existing['step_revision']!==$data['step_revision']
                    || $existing['resolution']!==$data['resolution']
                    || $existing['receipt_reference']!==$data['receipt_reference']
                    || $existing['requested_by']!==$data['requested_by']) {
                    throw new DomainException('Kernel recovery request ID reuse conflicts with its original intent.');
                }
                return $petition->requestId;
            }
            $this->db->insert('cos_workflow_recovery_decisions',$data);
            return $petition->requestId;
        });
    }

    public function approve(WorkflowRunCommand $cmd,string $requestId):void
    {
        self::authorized($cmd);
        self::requestId($requestId);
        $this->db->transactional(function()use($cmd,$requestId):void {
            [$run,$step]=$this->lockState($cmd->identity);
            $row=$this->db->fetchAssociative(
                'SELECT * FROM cos_workflow_recovery_decisions
                 WHERE organization_id=:org AND request_id=:request FOR UPDATE',
                ['org'=>$cmd->identity->organizationId,'request'=>$requestId],
            );
            if(!$row || $row['status']!=='REQUESTED'
                || $row['run_id']!==$cmd->identity->runId
                || $row['step_id']!=='execute'
                || $row['resolution']!=='verified_complete'
                || $row['requested_by']===$cmd->actor->userId()->value()) {
                throw new DomainException('Independent second manager must approve the same Kernel recovery.');
            }
            self::eligible($run,$step,(int)$row['run_revision'],(int)$row['step_revision']);
            if($cmd->expectedRevision!==(int)$row['run_revision']) {
                throw new DomainException('Kernel recovery approval has stale Run revision.');
            }
            $changed=$this->db->executeStatement(
                'UPDATE cos_workflow_recovery_decisions
                 SET status="APPROVED",approved_by=:by,approved_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND request_id=:request
                   AND status="REQUESTED" AND requested_by<>:by',
                ['by'=>$cmd->actor->userId()->value(),
                 'org'=>$cmd->identity->organizationId,'request'=>$requestId],
            );
            if($changed!==1) throw new DomainException('Concurrent/self-approved Kernel recovery denied.');
        });
    }

    public function reconcile(WorkflowRunCommand $cmd,string $requestId):void
    {
        self::authorized($cmd);
        self::requestId($requestId);
        if(!$this->owns($cmd->identity)) {
            throw new DomainException('Kernel recovery cannot target another canonical owner.');
        }
        $this->store->reconcileKernelApproved($cmd,$requestId);
    }

    private function lockState(WorkflowRunIdentity $id):array
    {
        $run=$this->db->fetchAssociative(
            'SELECT definition_id,state,revision FROM cos_workflow_runs
             WHERE organization_id=:org AND run_id=:run FOR UPDATE',
            ['org'=>$id->organizationId,'run'=>$id->runId],
        );
        $step=$this->db->fetchAssociative(
            'SELECT state,revision,attempts,claim_expires_at FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run AND step_id="execute" FOR UPDATE',
            ['org'=>$id->organizationId,'run'=>$id->runId],
        );
        if(!$run || !$step || !in_array($run['definition_id'],
            ['kernel.agent_execution','kernel.tool_execution'],true)) {
            throw new DomainException('Kernel recovery owner/Step not found.');
        }
        return [$run,$step];
    }

    private static function eligible(array $run,array $step,int $runRev,int $stepRev):void
    {
        if((int)$run['revision']!==$runRev || (int)$step['revision']!==$stepRev
            || !in_array($run['state'],['running','ambiguous'],true)
            || !in_array($step['state'],['claimed','ambiguous'],true)
            || (int)$step['attempts']!==1
            || ($step['state']==='claimed' && ($step['claim_expires_at']===null
                || strtotime((string)$step['claim_expires_at'].' UTC')>time()))) {
            throw new DomainException('Kernel recovery must target a stale/ambiguous claim without dispatch.');
        }
    }

    private static function authorized(WorkflowRunCommand $cmd):void
    {
        if($cmd->actor->organizationId()->value()!==$cmd->identity->organizationId
            || !$cmd->actor->isManager()
            || !$cmd->actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Kernel recovery requires an authenticated manager of this tenant.');
        }
    }

    private static function requestId(string $id):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)) {
            throw new DomainException('Kernel recovery request ID is invalid.');
        }
    }
}
