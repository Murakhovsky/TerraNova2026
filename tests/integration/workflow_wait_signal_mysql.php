<?php
declare(strict_types=1);

/**
 * Real SQL proof of durable evidence-only WAITING -> completed.
 * Only a stub independent verifier is supplied; production uses the
 * Domain-owned read-model receipt registry (tested in the non-Action suite).
 * No external network or Action/Agent/Tool is dispatched.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use Doctrine\DBAL\DriverManager;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Workflow\Model\StepStatus;
use Kernel\Workflow\Model\WorkflowStatus;
use Platform\Workflow\Contract\WorkflowReceiptVerifierInterface;
use Platform\Workflow\Contract\WorkflowStepClaimAdmissionInterface;
use Platform\Workflow\Model\WorkflowReceiptAttestation;
use Platform\Workflow\Model\WorkflowReceiptRequest;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunTransition;
use Platform\Workflow\Model\WorkflowEvidenceSignal;
use Platform\Workflow\Model\WorkflowStepClaim;
use Platform\Workflow\Model\WorkflowStepCheckpoint;
use Platform\Workflow\Model\WorkflowStepSnapshot;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),
    'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(6));
$org='wf-wait-'.$token;
$id=new WorkflowRunIdentity($org,'run-'.$token);
$actor=new TenantContext(
    UserId::fromString('manager-'.$token),OrganizationId::fromString($org),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$other=new TenantContext(
    UserId::fromString('other-'.$token),
    OrganizationId::fromString('other-'.$token),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)],
);
$ref='domain:read-proof:'.$token;
$input=hash('sha256','wf-wait-proof-'.$token);
$graph=new WorkflowDefinitionSnapshot('federation.approved-plan','1.0.0',[[
    'id'=>'await',
    'capability_id'=>'growth.read',
    'capability_version'=>'1.0.0',
    'execution_binding'=>'read:domain.sample.v1',
    'owner_domain'=>'growth',
    'side_effect_level'=>'none',
    'depends_on'=>[],
    'evidence'=>['kind'=>'domain.sample.v1','run_id'=>$id->runId,
        'input_digest'=>$input,'reference'=>$ref],
]]);
$verifier=new class($ref,$input) implements WorkflowReceiptVerifierInterface {
    public int $checks=0;
    public function __construct(private string $reference,private string $digest) {}
    public function attest(WorkflowReceiptRequest $request):WorkflowReceiptAttestation {
        ++$this->checks;
        if($request->resultReference!==$this->reference
            || $request->inputDigest!==$this->digest
            || $request->stepId!=='await') {
            throw new DomainException('No independent persisted Domain signal matches.');
        }
        return new WorkflowReceiptAttestation($request->identity,$request->stepId,true,
            'mocked-independent-domain-read-model',$this->reference,
            'signal-'.$request->identity->runId);
    }
};
$admission=new class implements WorkflowStepClaimAdmissionInterface {
    public function assertAuthorized(WorkflowStepClaim $claim,WorkflowStepSnapshot $step):void {
        if($step->sideEffectLevel!=='none') throw new DomainException('Not read-only.');
    }
};
$store=new DoctrineWorkflowExecutionStore($db,$verifier,$admission);
$reject=static function(callable $call,string $label):void {
    try{$call();}catch(Throwable){return;}
    throw new RuntimeException('Unsafe Wait/Signal accepted: '.$label);
};
$command=static fn(int $revision):WorkflowRunCommand=>
    new WorkflowRunCommand($id,$actor,$revision,'signal-'.$id->runId);
try {
    $draft=new WorkflowRunDraft($id,$graph->id,$graph->version,$input,
        $actor->userId()->value(),'signal-'.$id->runId,'plan-'.$token,
        'approval-'.$token,hash('sha256','signed-'.$token),$graph);
    $store->create($draft,$actor);
    $store->compareAndTransition(new WorkflowRunTransition($command(1),
        WorkflowStatus::CREATED,WorkflowStatus::RUNNING,'start read-only evidence wait'));
    $claimed=$store->claim(new WorkflowStepClaim($command(2),'await','domain-wait',300));
    if(!is_string($claimed->claimToken) || $claimed->revision!==2) {
        throw new RuntimeException('Missing fenced signal wait claim.');
    }
    $reject(static fn()=> $store->completeWaitingEvidence(new WorkflowEvidenceSignal(
        $command(3),'await',2,$ref)), 'signal before Step entered WAITING');
    $waiting=$store->checkpoint(new WorkflowStepCheckpoint($command(3),'await',
        $claimed->claimToken,StepStatus::WAITING));
    if($waiting->state!=='waiting' || $waiting->claimToken!==null
        || $store->get($id)?->revision!==4) {
        throw new RuntimeException('WAITING must persist without a live claim or side effect.');
    }
    $reject(static fn()=> $store->claim(
        new WorkflowStepClaim($command(4),'await','second-worker',300)),
        'claim waiting Step as a new worker');
    $reject(static fn()=> $store->completeWaitingEvidence(new WorkflowEvidenceSignal(
        $command(4),'await',3,'domain:forged')), 'forged Domain signal');
    $reject(static fn()=> $store->completeWaitingEvidence(new WorkflowEvidenceSignal(
        new WorkflowRunCommand($id,$other,4,'signal-'.$id->runId),
        'await',3,$ref)), 'cross-tenant signal');
    $reject(static fn()=> $store->completeWaitingEvidence(new WorkflowEvidenceSignal(
        $command(3),'await',3,$ref)), 'stale Run CAS');
    if($store->get($id)?->revision!==4
        || $store->steps($id)[0]->state!=='waiting') {
        throw new RuntimeException('Rejected signals modified technical Run/Step.');
    }
    $completed=$store->completeWaitingEvidence(new WorkflowEvidenceSignal(
        $command(4),'await',3,$ref));
    if($completed->state!=='completed'
        || $completed->resultReference!==$ref
        || $store->get($id)?->revision!==5) {
        throw new RuntimeException('Independently verified Signal did not atomically complete.');
    }
    $reject(static fn()=> $store->completeWaitingEvidence(new WorkflowEvidenceSignal(
        $command(5),'await',4,$ref)), 'replayed signal after completion');
    $store->compareAndTransition(new WorkflowRunTransition($command(5),
        WorkflowStatus::RUNNING,WorkflowStatus::COMPLETED,'verify final read-only receipt'));
    if($store->get($id)?->status!==WorkflowStatus::COMPLETED || $verifier->checks<2) {
        throw new RuntimeException('Final Wait/Signal receipt re-attestation failed.');
    }
    echo "Workflow durable Wait/Signal real MySQL passed: one fenced read-only resume, negative signal/replay/tenant, verified final receipt.\n";
} finally {
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
}
