<?php
declare(strict_types=1);

/**
 * Synthetic canonical Action DB fixture. This proves receipt validation only;
 * it neither performs real Domain mutations nor authorizes Action dispatch.
 */
require (string) (getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/symfony/vendor/autoload.php');

use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowPlanApprovalEvidence;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use Doctrine\DBAL\DriverManager;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\WorkflowStatus;
use Platform\Workflow\Contract\WorkflowPlanApprovalEvidenceInterface;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepSnapshot;

$connection = DriverManager::getConnection([
    'driver'=>'pdo_mysql', 'host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),
    'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(6));
$org='wf-proof-'.$token;
$actor=new TenantContext(
    UserId::fromString('wf-manager-'.$token),
    OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$runId='action-run-'.$token;
$id=new WorkflowRunIdentity($org,$runId);
$planId='plan-'.$token;
$planDigest=hash('sha256','approved-action-plan-'.$token);
$approvalRef='approved-plan-'.$token;
$definition=new WorkflowDefinitionSnapshot('test.action-proof','1.0.0',[
    ['id'=>'publish','capability_id'=>'test.publish','capability_version'=>'1.0.0',
     'side_effect_level'=>'external','depends_on'=>[],
     'execution_binding'=>'action:test.publish',
     'input'=>['target_type'=>'case','target_id'=>'case-ci','parameters'=>['approved'=>true,'n'=>1]]],
]);
$draft=new WorkflowRunDraft(
    $id,$definition->id,$definition->version,hash('sha256','input-'.$token),
    $actor->userId()->value(),'corr-'.$runId,$planId,$approvalRef,$planDigest,$definition,
);
$claims=new class implements WorkflowStepClaimAdmissionInterface {
    public function assertAuthorized(WorkflowStepClaim $claim, WorkflowStepSnapshot $step): void
    {
        if ($step->capabilityId!=='test.publish') {
            throw new DomainException('Test fixture Action claim mismatch.');
        }
    }
};
$store=new DoctrineWorkflowExecutionStore($connection,new FailClosedWorkflowReceiptVerifier(),$claims);
$planEvidence=new class($id,$planId,$planDigest,$approvalRef) implements WorkflowPlanApprovalEvidenceInterface {
    public function __construct(
        private WorkflowRunIdentity $expected,
        private string $planId,
        private string $digest,
        private string $approval,
    ) {}
    public function assertTrusted(
        WorkflowRunIdentity $identity,string $planId,string $planDigest,string $approvalReference,
    ):void {
        if ($identity!=$this->expected || $planId!==$this->planId
            || $planDigest!==$this->digest || $approvalReference!==$this->approval) {
            throw new DomainException('Fixture Plan provenance mismatch.');
        }
    }
};
$verifier=new DoctrineWorkflowActionReceiptVerifier($connection,$planEvidence);
$unwired=new DoctrineWorkflowActionReceiptVerifier($connection,new FailClosedWorkflowPlanApprovalEvidence());
$expectDenied=static function(callable $callback,string $name):void {
    try { $callback(); } catch(Throwable) { return; }
    throw new RuntimeException('Untrusted canonical Action receipt accepted: '.$name);
};
$actionId=bin2hex(random_bytes(16));
$actionRef='action:'.$actionId;
$proofRequest=new WorkflowReceiptRequest($id,$actor,'publish',$actionRef,$draft->inputDigest);
$keys=['org'=>$org,'run'=>$runId];
try {
    $store->create($draft,$actor);
    $command=new WorkflowRunCommand($id,$actor,1,$draft->correlationId);
    $store->compareAndTransition(new WorkflowRunTransition(
        $command,WorkflowStatus::CREATED,WorkflowStatus::RUNNING,'synthetic receipt test',
    ));
    $store->claim(new WorkflowStepClaim(
        new WorkflowRunCommand($id,$actor,2,$draft->correlationId),
        'publish','fixture-worker',120,
    ));
    $step=$connection->fetchAssociative(
        'SELECT idempotency_key FROM cos_workflow_steps
         WHERE organization_id=:org AND run_id=:run AND step_id="publish"',$keys,
    );
    if(!$step) throw new RuntimeException('Fixture claimed Workflow Step missing.');

    $connection->executeStatement(
        'INSERT INTO cos_actions
           (id,organization_id,type,target_type,target_id,parameters,
            source_type,source_id,status,execution_mode,risk_level,
            idempotency_key,correlation_id,executed_at)
         VALUES (:id,:org,:type,:target_type,:target_id,:parameters,
                 "USER",:actor,"COMPLETED","APPROVAL_REQUIRED","HIGH",
                 :key,:correlation,NOW(6))',
        ['id'=>$actionId,'org'=>$org,'type'=>'test.publish',
            'target_type'=>'case','target_id'=>'case-ci',
            'parameters'=>json_encode(['n'=>1,'approved'=>true],JSON_THROW_ON_ERROR),
            'actor'=>$actor->userId()->value(),
            'key'=>'wf:'.$step['idempotency_key'],'correlation'=>$draft->correlationId],
    );
    $connection->executeStatement(
        'INSERT INTO cos_action_attempts
           (organization_id,action_id,attempt,status,started_at,finished_at)
         VALUES (:org,:id,1,"COMPLETED",NOW(6),NOW(6))',
        ['org'=>$org,'id'=>$actionId],
    );
    $policyId=bin2hex(random_bytes(16));
    $connection->executeStatement(
        'INSERT INTO cos_policy_evaluations
           (id,organization_id,action_id,policy_id,decision,correlation_id,evaluated_at)
         VALUES (:id,:org,:action,NULL,"APPROVAL_REQUIRED",:correlation,NOW(6))',
        ['id'=>$policyId,'org'=>$org,'action'=>$actionId,
            'correlation'=>$draft->correlationId],
    );
    $approvalId=bin2hex(random_bytes(16));
    $independent='wf-approver-'.$token;
    $connection->executeStatement(
        'INSERT INTO cos_approvals
           (id,organization_id,action_id,status,approver_type,approver_id,
            requested_by_type,requested_by_id,decided_by_type,decided_by_id,
            decided_at,expires_at)
         VALUES (:id,:org,:action,"APPROVED","USER",:approver,"USER",:owner,
                 "USER",:approver,NOW(6),DATE_ADD(NOW(6),INTERVAL 1 DAY))',
        ['id'=>$approvalId,'org'=>$org,'action'=>$actionId,
         'approver'=>$independent,'owner'=>$actor->userId()->value()],
    );

    $proof=$verifier->attest($proofRequest);
    if(!$proof->verified || $proof->evidenceReference!==$actionRef
        || $proof->provider!=='canonical-action') {
        throw new RuntimeException('Canonical Action receipt proof was not attested.');
    }
    $expectDenied(static fn()=> $unwired->attest($proofRequest),'unwired production Plan gate');
    $expectDenied(static fn()=> $verifier->attest(new WorkflowReceiptRequest(
        $id,$actor,'publish','action:'.bin2hex(random_bytes(16)),$draft->inputDigest,
    )),'wrong Action id');
    $connection->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:actor WHERE organization_id=:org AND action_id=:action',
        ['actor'=>$actor->userId()->value(),'org'=>$org,'action'=>$actionId],
    );
    $expectDenied(static fn()=> $verifier->attest($proofRequest),'self approval');
    $connection->executeStatement(
        'UPDATE cos_approvals SET decided_by_id=:actor WHERE organization_id=:org AND action_id=:action',
        ['actor'=>$independent,'org'=>$org,'action'=>$actionId],
    );
    $connection->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="DENIED" WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actionId],
    );
    $expectDenied(static fn()=> $verifier->attest($proofRequest),'latest denied Policy');
    $connection->executeStatement(
        'UPDATE cos_policy_evaluations SET decision="APPROVAL_REQUIRED" WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actionId],
    );
    $connection->executeStatement(
        'INSERT INTO cos_action_attempts
           (organization_id,action_id,attempt,status,started_at,finished_at)
         VALUES (:org,:id,2,"COMPLETED",NOW(6),NOW(6))',
        ['org'=>$org,'id'=>$actionId],
    );
    $expectDenied(static fn()=> $verifier->attest($proofRequest),'more than one execution attempt');
    $connection->executeStatement(
        'DELETE FROM cos_action_attempts WHERE organization_id=:org AND action_id=:id AND attempt=2',
        ['org'=>$org,'id'=>$actionId],
    );
    $connection->executeStatement(
        'UPDATE cos_actions SET parameters=:forged WHERE organization_id=:org AND id=:id',
        ['forged'=>json_encode(['approved'=>false,'n'=>1],JSON_THROW_ON_ERROR),
         'org'=>$org,'id'=>$actionId],
    );
    $expectDenied(static fn()=> $verifier->attest($proofRequest),'mutable Action parameters');
    $connection->executeStatement(
        'UPDATE cos_actions SET parameters=:original WHERE organization_id=:org AND id=:id',
        ['original'=>json_encode(['approved'=>true,'n'=>1],JSON_THROW_ON_ERROR),
         'org'=>$org,'id'=>$actionId],
    );
    $connection->executeStatement(
        'UPDATE cos_approvals SET status="EXPIRED" WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actionId],
    );
    $expectDenied(static fn()=> $verifier->attest($proofRequest),'revoked human approval');
    $connection->executeStatement(
        'UPDATE cos_approvals SET status="APPROVED" WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actionId],
    );
    $connection->executeStatement(
        'UPDATE cos_workflow_runs SET source_plan_digest=:wrong WHERE organization_id=:org AND run_id=:run',
        [...$keys,'wrong'=>hash('sha256','forged-plan')],
    );
    $expectDenied(static fn()=> $verifier->attest($proofRequest),'pinned Plan digest drift');
    $connection->executeStatement(
        'UPDATE cos_workflow_runs SET source_plan_digest=:valid WHERE organization_id=:org AND run_id=:run',
        [...$keys,'valid'=>$planDigest],
    );
    $savedDefinition=$connection->fetchOne(
        'SELECT definition_json FROM cos_workflow_runs WHERE organization_id=:org AND run_id=:run',
        $keys,
    );
    if(!is_string($savedDefinition)) throw new RuntimeException('Missing Workflow graph fixture.');
    $corrupt=json_decode($savedDefinition,true,512,JSON_THROW_ON_ERROR);
    $corrupt[0]['input']['parameters']['approved']=false;
    $connection->executeStatement(
        'UPDATE cos_workflow_runs SET definition_json=:changed WHERE organization_id=:org AND run_id=:run',
        [...$keys,'changed'=>json_encode($corrupt,JSON_THROW_ON_ERROR)],
    );
    $expectDenied(static fn()=> $verifier->attest($proofRequest),'mutated immutable Workflow graph');
    $connection->executeStatement(
        'UPDATE cos_workflow_runs SET definition_json=:original WHERE organization_id=:org AND run_id=:run',
        [...$keys,'original'=>$savedDefinition],
    );
    if(!$verifier->attest($proofRequest)->verified) {
        throw new RuntimeException('Canonical Action receipt did not recover after restored trusted evidence.');
    }
    echo "Workflow canonical Action receipt MySQL passed: approved Plan port, immutable input, Action key, independent human approval, Policy and exactly-one attempt.\n";
} finally {
    $connection->executeStatement('DELETE FROM cos_approvals WHERE organization_id=:org',['org'=>$org]);
    $connection->executeStatement('DELETE FROM cos_policy_evaluations WHERE organization_id=:org',['org'=>$org]);
    $connection->executeStatement('DELETE FROM cos_action_attempts WHERE organization_id=:org',['org'=>$org]);
    $connection->executeStatement('DELETE FROM cos_actions WHERE organization_id=:org',['org'=>$org]);
    $connection->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $connection->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
}
