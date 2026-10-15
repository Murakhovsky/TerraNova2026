<?php
declare(strict_types=1);

/**
 * Genuine MySQL two-person Workflow recovery acceptance.
 * Canonical Action proof is independently sourced from actual Action/attempt/
 * Policy/Approval SQL; Plan evidence is a scoped fixture (not live dispatch).
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\DoctrineWorkflowRecoveryInspector;
use App\Persistence\Workflow\DoctrineWorkflowRecoveryManager;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use Doctrine\DBAL\DriverManager;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\WorkflowStatus;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRecoveryDisposition;
use Platform\Workflow\Model\WorkflowRecoveryPetition;
use Platform\Workflow\Model\WorkflowRecoveryResolution;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepSnapshot;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),'charset'=>'utf8mb4',
]);
$tag=bin2hex(random_bytes(6));
$org='wf-recovery-'.$tag;
$manager=static fn(string $user,string $tenant):TenantContext => new TenantContext(
    UserId::fromString($user),OrganizationId::fromString($tenant),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$first=$manager('manager-one-'.$tag,$org);
$second=$manager('manager-two-'.$tag,$org);
$foreign=$manager('foreign-'.$tag,'foreign-org-'.$tag);
$identity=static fn(string $name):WorkflowRunIdentity =>
    new WorkflowRunIdentity($org,$name.'-'.$tag);
$command=static fn(WorkflowRunIdentity $id,TenantContext $actor,int $rev,?string $requestId=null):WorkflowRunCommand =>
    new WorkflowRunCommand($id,$actor,$rev,'corr-'.$id->runId,$requestId);
$plan=new class implements WorkflowPlanApprovalEvidenceInterface {
    public function assertTrusted(
        WorkflowRunIdentity $identity,string $planId,string $planDigest,string $approvalReference,
    ):void {
        if($planId!=='plan-'.$identity->runId
            || $planDigest!==hash('sha256','approved-'.$identity->runId)
            || $approvalReference!=='approval-'.$identity->runId) {
            throw new DomainException('Synthetic scoped Plan provenance is not approved.');
        }
    }
};
$actionProof=new DoctrineWorkflowActionReceiptVerifier($db,$plan);
$nonAction=new class implements WorkflowNonActionReceiptEvidenceInterface {
    public function assertVerified(
        WorkflowRunIdentity $identity,string $stepId,string $reference,
        string $inputDigest,array $pinnedStep,
    ):void {
        if($stepId!=='read' || $reference!=='domain:verified:read'
            || ($pinnedStep['execution_binding']??null)!=='read:fixture') {
            throw new DomainException('No independent non-Action proof for test fixture.');
        }
    }
};
$managerService=new DoctrineWorkflowRecoveryManager(
    $db,new DoctrineWorkflowRecoveryInspector($db,$actionProof),
    $actionProof,$nonAction,
);
$deny=static function(callable $fn,string $why):void {
    try{$fn();}catch(DomainException|LogicException|InvalidArgumentException){return;}
    throw new RuntimeException('Unsafe recovery was accepted: '.$why);
};
$fixtureClaim=new class implements WorkflowStepClaimAdmissionInterface {
    public function assertAuthorized(WorkflowStepClaim $claim,WorkflowStepSnapshot $step):void {
        if($step->sideEffectLevel!=='external')
            throw new DomainException('Unexpected Workflow Action claim.');
    }
};
$store=new DoctrineWorkflowExecutionStore($db,new FailClosedWorkflowReceiptVerifier(),$fixtureClaim);
$externalStep=['id'=>'send','capability_id'=>'test.publish','capability_version'=>'1.0.0',
    'side_effect_level'=>'external','depends_on'=>[],
    'execution_binding'=>'action:test.publish',
    'input'=>['target_type'=>'case','target_id'=>'case-'.$tag,
        'parameters'=>['approved'=>true,'sequence'=>1]]];
$readStep=['id'=>'read','capability_id'=>'test.read','capability_version'=>'1.0.0',
    'side_effect_level'=>'none','execution_binding'=>'read:fixture','depends_on'=>[]];
$allIds=[];
$create=function(string $name,bool $external=true) use (
    $identity,$store,$first,$command,$tag,$externalStep,$readStep,$db,&$allIds
):array {
    $id=$identity($name);$allIds[]=$id->runId;
    $steps=$external?[$externalStep]:[$readStep];
    $definition=new WorkflowDefinitionSnapshot('test.recovery','1.0.0',$steps);
    $draft=new WorkflowRunDraft(
        $id,$definition->id,$definition->version,
        hash('sha256','input-'.$id->runId),$first->userId()->value(),
        'corr-'.$id->runId,
        'plan-'.$id->runId,'approval-'.$id->runId,
        hash('sha256','approved-'.$id->runId),$definition,
    );
    $store->create($draft,$first);
    $store->compareAndTransition(new WorkflowRunTransition(
        $command($id,$first,1),WorkflowStatus::CREATED,WorkflowStatus::RUNNING,'start test Run',
    ));
    $claim=$store->claim(new WorkflowStepClaim(
        $command($id,$first,2),$external?'send':'read','recovery-fixture-worker',120,
    ));

    $db->executeStatement(
        'UPDATE cos_workflow_steps SET claim_expires_at=DATE_SUB(NOW(6),INTERVAL 2 MINUTE)
         WHERE organization_id=:org AND run_id=:run AND step_id=:step',
        ['org'=>$id->organizationId,'run'=>$id->runId,'step'=>$external?'send':'read'],
    );
    return [$id,$claim];
};
$petition=static fn(
    WorkflowRunIdentity $id,TenantContext $actor,int $runRev,string $step,
    int $stepRev,WorkflowRecoveryResolution $resolution,?string $receipt=null,
):WorkflowRecoveryPetition =>new WorkflowRecoveryPetition(
    $command($id,$actor,$runRev),bin2hex(random_bytes(16)),
    $step,$stepRev,$resolution,
    'Two managers checked the uncertain worker effect and recovery evidence.', $receipt,
);
$proofAction=function(WorkflowRunIdentity $id) use ($db,$first,$second,$externalStep,$tag):string {
    $actionId=bin2hex(random_bytes(16));
    $step=$db->fetchAssociative(
        'SELECT idempotency_key FROM cos_workflow_steps
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',
        ['org'=>$id->organizationId,'run'=>$id->runId],
    );
    if(!$step)throw new RuntimeException('Missing persisted Action Step.');
    $db->executeStatement(
        'INSERT INTO cos_actions
         (id,organization_id,type,target_type,target_id,parameters,source_type,
          source_id,status,execution_mode,risk_level,idempotency_key,correlation_id,executed_at)
         VALUES (:id,:org,"test.publish","case",:target,:params,"USER",:actor,
                 "COMPLETED","APPROVAL_REQUIRED","HIGH",:key,:corr,NOW(6))',
        ['id'=>$actionId,'org'=>$id->organizationId,
         'target'=>$externalStep['input']['target_id'],
         'params'=>json_encode($externalStep['input']['parameters'],JSON_THROW_ON_ERROR),
         'actor'=>$first->userId()->value(),
         'key'=>'wf:'.$step['idempotency_key'],'corr'=>'corr-'.$id->runId],
    );
    $db->executeStatement(
        'INSERT INTO cos_action_attempts
         (organization_id,action_id,attempt,worker_id,status,started_at,finished_at)
         VALUES (:org,:action,1,"recovery-fixture","COMPLETED",NOW(6),NOW(6))',
        ['org'=>$id->organizationId,'action'=>$actionId],
    );
    $db->executeStatement(
        'INSERT INTO cos_policy_evaluations
         (id,organization_id,action_id,decision,correlation_id,evaluated_at)
         VALUES (:id,:org,:action,"APPROVAL_REQUIRED",:corr,NOW(6))',
        ['id'=>bin2hex(random_bytes(16)),'org'=>$id->organizationId,
         'action'=>$actionId,'corr'=>'corr-'.$id->runId],
    );
    $db->executeStatement(
        'INSERT INTO cos_approvals
         (id,organization_id,action_id,status,approver_type,approver_id,
          requested_by_type,requested_by_id,decided_by_type,decided_by_id,
          decided_at,expires_at)
         VALUES (:id,:org,:action,"APPROVED","USER",:approver,"USER",:owner,
                 "USER",:approver,NOW(6),DATE_ADD(NOW(6),INTERVAL 1 DAY))',
        ['id'=>bin2hex(random_bytes(16)),'org'=>$id->organizationId,
         'action'=>$actionId,'owner'=>$first->userId()->value(),
         'approver'=>$second->userId()->value()],
    );
    return 'action:'.$actionId;
};
try {
    // Crash after effect: status/attempt are COMPLETED, Step claim never checkpointed.
    [$id,$claim]=$create('completed');
    // Microsecond active leases cannot be considered expired by PHP second rounding.
    $db->executeStatement(
        'UPDATE cos_workflow_steps
         SET claim_expires_at=DATE_ADD(NOW(6),INTERVAL 500000 MICROSECOND)
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',
        ['org'=>$org,'run'=>$id->runId],
    );
    $early=$petition($id,$first,3,'send',2,WorkflowRecoveryResolution::QUARANTINE);
    $deny(static fn()=>$managerService->request($early),'still-active subsecond lease');
    $db->executeStatement(
        'UPDATE cos_workflow_steps
         SET claim_expires_at=DATE_SUB(NOW(6),INTERVAL 2 MINUTE)
         WHERE organization_id=:org AND run_id=:run AND step_id="send"',
        ['org'=>$org,'run'=>$id->runId],
    );
    $expired=$managerService->expiredClaims($command($id,$first,3));
    if(!array_filter($expired,static fn($row):bool=>$row['run_id']===$id->runId)) {
        throw new RuntimeException('Expired Action claim was not surfaced to operators.');
    }
    $quarantine=$petition($id,$first,3,'send',2,WorkflowRecoveryResolution::QUARANTINE);
    $rid=$managerService->request($quarantine);
    $deny(static fn()=>$managerService->approve($command($id,$first,3),$rid),
        'requester self approval');
    $deny(static fn()=>$managerService->reconcile($command($id,$first,3,$rid)),
        'mutate without independent Approval');
    $managerService->approve($command($id,$second,3),$rid);
    $assessment=$managerService->reconcile($command($id,$first,3,$rid));
    if($assessment->reason!=='approved_quarantine' || !$assessment->externalOutcomeUnknown
        || $db->fetchOne(
            'SELECT state FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
            ['org'=>$org,'run'=>$id->runId])!=='ambiguous') {
        throw new RuntimeException('Expired external crash was not quarantined without replay.');
    }
    $queue=$managerService->expiredClaims($command($id,$first,4));
    if(!array_filter($queue,static fn($row):bool=>
        $row['run_id']===$id->runId && $row['step_state']==='ambiguous')) {
        throw new RuntimeException('Quarantined external work disappeared from the recovery worklist.');
    }
    if($managerService->reconcile($command($id,$first,3,$rid))->reason!=='recovery_request_already_applied') {
        throw new RuntimeException('Recovery idempotent reapply did not no-op.');
    }

    $ref=$proofAction($id);
    $complete=$petition($id,$first,4,'send',3,WorkflowRecoveryResolution::VERIFIED_COMPLETE,$ref);
    $completeId=$managerService->request($complete);
    $managerService->approve($command($id,$second,4),$completeId);
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="DENIED"
         WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>substr($ref,7)],
    );
    $deny(static fn()=>$managerService->reconcile($command($id,$first,4,$completeId)),
        'revoked Action Policy');
    $db->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="APPROVAL_REQUIRED"
         WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>substr($ref,7)],
    );
    $done=$managerService->reconcile($command($id,$first,4,$completeId));
    if($done->disposition!==WorkflowRecoveryDisposition::RECONCILE_ONLY
        || $db->fetchOne('SELECT state FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run AND step_id="send"',
            ['org'=>$org,'run'=>$id->runId])!=='completed'
        || $db->fetchOne('SELECT state FROM cos_workflow_runs
             WHERE organization_id=:org AND run_id=:run',
            ['org'=>$org,'run'=>$id->runId])!=='waiting') {
        throw new RuntimeException('Verified external outcome did not safely complete and wait.');
    }
    if($managerService->reconcile($command($id,$first,4,$rid))->reason!=='recovery_request_already_applied') {
        throw new RuntimeException('Prior Recovery decision was not idempotently fenced.');
    }

    // Crash before any external effect: only side_effect_level=none may reset.
    [$readId,$readClaim]=$create('read-only',false);
    $readRequest=$petition($readId,$first,3,'read',2,WorkflowRecoveryResolution::RESET_READ_ONLY);
    $readKey=$managerService->request($readRequest);
    $managerService->approve($command($readId,$second,3),$readKey);
    $readResult=$managerService->reconcile($command($readId,$first,3,$readKey));
    if($readResult->disposition!==WorkflowRecoveryDisposition::SAFE_PRE_EFFECT_RETRY
        || $db->fetchOne('SELECT state FROM cos_workflow_steps
             WHERE organization_id=:org AND run_id=:run AND step_id="read"',
            ['org'=>$org,'run'=>$readId->runId])!=='pending') {
        throw new RuntimeException('Read-only crash recovery did not allow a bounded safe retry.');
    }
    // Retrying read-only must preserve historical attempts and produce a fresh claim.
    $store->claim(new WorkflowStepClaim($command($readId,$first,4),'read','new-worker',60));
    $attempts=(int)$db->fetchOne(
        'SELECT attempts FROM cos_workflow_steps
         WHERE organization_id=:org AND run_id=:run AND step_id="read"',
        ['org'=>$org,'run'=>$readId->runId],
    );
    if($attempts!==2)throw new RuntimeException('Recovered read-only attempt counter was erased.');

    // Unknown remote effect MUST NOT be marked completed without proof or replayed.
    [$uncertainId]=$create('unknown');
    $bad=$petition($uncertainId,$first,3,'send',2,
        WorkflowRecoveryResolution::VERIFIED_COMPLETE,'action:'.bin2hex(random_bytes(16)));
    $badId=$managerService->request($bad);
    $managerService->approve($command($uncertainId,$second,3),$badId);
    $deny(static fn()=>$managerService->reconcile($command($uncertainId,$first,3,$badId)),
        'completion without real Action');
    $cancel=$petition($uncertainId,$first,3,'send',2,WorkflowRecoveryResolution::CANCEL_UNCERTAIN);
    $cancelId=$managerService->request($cancel);
    $managerService->approve($command($uncertainId,$second,3),$cancelId);
    $cancelled=$managerService->reconcile($command($uncertainId,$first,3,$cancelId));
    if($cancelled->disposition!==WorkflowRecoveryDisposition::NO_ACTION
        || !$cancelled->externalOutcomeUnknown
        || $db->fetchOne('SELECT state FROM cos_workflow_runs
             WHERE organization_id=:org AND run_id=:run',
            ['org'=>$org,'run'=>$uncertainId->runId])!=='cancelled') {
        throw new RuntimeException('Human risk acceptance did not cancel uncertain external work.');
    }
    $deny(static fn()=>$managerService->approve($command($id,$foreign,4),$completeId),
        'cross-tenant recovery approval');

    $audits=$db->fetchAllAssociative(
        'SELECT request_id,event_type,actor_id FROM cos_workflow_recovery_events
         WHERE organization_id=:org ORDER BY id',['org'=>$org],
    );
    if(count($audits)<12
        || count(array_filter($audits,static fn($event):bool=>$event['event_type']==='APPLIED'))!==4) {
        throw new RuntimeException('Recovery approval/audit history was not durably recorded.');
    }
    echo "Safe Workflow recovery MySQL passed: 2-person approval, expired claim, quarantine, canonical Action proof, revoked Policy, cancel risk, read-only retry, CAS, audit and no replay.\n";
} finally {
    $db->executeStatement('DELETE FROM cos_workflow_recovery_events WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_recovery_decisions WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_approvals WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_policy_evaluations WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_action_attempts WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_actions WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
}
