<?php
declare(strict_types=1);

/**
 * Real domain-owned MySQL evidence; not a synthetic receipt provider.
 * No external Action dispatch and no production Workflow cutover.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Workflow\WorkflowNonActionReceiptRegistry;
use App\Persistence\Workflow\DocumentSignatureNonActionEvidence;
use App\Persistence\Workflow\CapitalResearchNonActionEvidence;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use Doctrine\DBAL\DriverManager;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),
    'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(5));
$org='wf-domain-'.$token;
$run='run-'.$token;
$id=new WorkflowRunIdentity($org,$run);
$inputDigest=hash('sha256','domain-input-'.$token);
$sig='sig-'.$token;$document='doc-'.$token;$signer='signer-'.$token;
$resultId='research-'.$token;$experimentId='experiment-'.$token;
$signatureReference='locally-recorded-signature-'.$token;
$documentRef='documents:signature:signed:v1:'.$sig.':'.hash('sha256',$signatureReference);
$record=[
    'result_id'=>$resultId,'experiment_id'=>$experimentId,'status'=>'VALIDATED',
    'financial_metrics'=>['pnl'=>20],'risk_metrics'=>['var'=>2],
    'data_quality'=>['trusted'=>true],'execution_metrics'=>[],'limitations'=>[],
];
$researchRef='capital_markets:research:validated:v1:'.$resultId.':'
    .WorkflowDefinitionFingerprint::digest([$record]);
$evidenceDoc=[
    'kind'=>'documents.signature.signed.v1','run_id'=>$run,
    'input_digest'=>$inputDigest,'signature_id'=>$sig,
    'document_id'=>$document,'signer_id'=>$signer,
];
$evidenceResearch=[
    'kind'=>'capital_markets.research_result.validated.v1','run_id'=>$run,
    'input_digest'=>$inputDigest,'result_id'=>$resultId,'experiment_id'=>$experimentId,
];
$docStep=[
    'id'=>'doc','capability_id'=>'documents.signature.observation',
    'capability_version'=>'1.0.0','side_effect_level'=>'none',
    'owner_domain'=>'platform.documents',
    'execution_binding'=>'read:documents.signature.signed.v1',
    'depends_on'=>[],'evidence'=>$evidenceDoc,
];
$researchStep=[
    'id'=>'research','capability_id'=>'capital_markets.research.observation',
    'capability_version'=>'1.0.0','side_effect_level'=>'none',
    'owner_domain'=>'capital_markets',
    'execution_binding'=>'read:capital_markets.research_result.validated.v1',
    'depends_on'=>[],'evidence'=>$evidenceResearch,
];
$role=new TenantContext(UserId::fromString('manager-'.$token),
    OrganizationId::fromString($org),OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)]);
$state=new class implements ModuleStateRepositoryInterface {
    public bool $enabled=true;
    public function enabledOverride(string $organizationId,string $moduleId):?bool {return $this->enabled;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {$this->enabled=$enabled;}
};
$modules=new ActiveModuleResolver(new ModuleCatalog([
    new ModuleDefinition(new ModuleManifest('capital_markets','Capital Markets','1.0.0'),new ModuleContributions()),
]),$state);
$docs=new DocumentSignatureNonActionEvidence($db);
$research=new CapitalResearchNonActionEvidence($db);
$registry=new WorkflowNonActionReceiptRegistry($modules,[$docs,$research]);
$store=new DoctrineWorkflowExecutionStore(
    $db,new FailClosedWorkflowReceiptVerifier(),new FailClosedWorkflowStepClaimAdmission(),
);
$reject=static function(callable $callback,string $message):void {
    try{$callback();}catch(DomainException){return;}
    throw new RuntimeException('Unsafe domain evidence was accepted: '.$message);
};
try {
    $store->create(new WorkflowRunDraft($id,'read-model','1.0.0',$inputDigest,
        $role->userId()->value(),'correlation-'.$token,
        definition:new WorkflowDefinitionSnapshot('read-model','1.0.0',[$docStep,$researchStep])),
        $role);
    $reject(static fn()=> $registry->assertVerified($id,'doc',$documentRef,$inputDigest,$docStep),
        'missing source record');

    // Domain-owned Documents records are created AFTER the canonical Run.
    $db->executeStatement(
        'INSERT INTO cos_documents
         (organization_id,document_id,title,status,created_by,updated_by,created_at)
         VALUES (:org,:doc,"Evidence fixture","active",1,1,NOW(6))',
        ['org'=>$org,'doc'=>$document],
    );
    $db->executeStatement(
        'INSERT INTO cos_document_signatures
         (organization_id,signature_id,document_id,signer_id,status,requested_by,
          signed_by,signed_by_actor_id,signature_reference,signed_at)
         VALUES (:org,:sig,:doc,:signer,"signed",1,:signer,2,:reference,NOW(6))',
        ['org'=>$org,'sig'=>$sig,'doc'=>$document,'signer'=>$signer,
            'reference'=>$signatureReference],
    );
    $db->executeStatement(
        'INSERT INTO tn_capital_market_research_results
         (organization_id,result_id,experiment_id,status,record_json,created_at)
         VALUES (:org,:result,:experiment,"VALIDATED",:json,NOW(6))',
        ['org'=>$org,'result'=>$resultId,'experiment'=>$experimentId,
            'json'=>json_encode($record,JSON_THROW_ON_ERROR)],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps
         SET state="completed",attempts=1,revision=3,result_reference=:ref,updated_at=NOW(6)
         WHERE organization_id=:org AND run_id=:run AND step_id="doc"',
        ['org'=>$org,'run'=>$run,'ref'=>$documentRef],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps
         SET state="completed",attempts=1,revision=3,result_reference=:ref,updated_at=NOW(6)
         WHERE organization_id=:org AND run_id=:run AND step_id="research"',
        ['org'=>$org,'run'=>$run,'ref'=>$researchRef],
    );
    $registry->assertVerified($id,'doc',$documentRef,$inputDigest,$docStep);
    $registry->assertVerified($id,'research',$researchRef,$inputDigest,$researchStep);
    $reject(static fn()=> $registry->assertVerified($id,'doc','documents:signature:forged',$inputDigest,$docStep),
        'forged signature reference');
    $reject(static fn()=> $registry->assertVerified($id,'research','research:forged',$inputDigest,$researchStep),
        'forged Research fingerprint');
    $reject(static fn()=> $registry->assertVerified(
        new WorkflowRunIdentity('other-tenant',$run),'doc',$documentRef,$inputDigest,$docStep,
    ),'foreign tenant signature');
    $reject(static fn()=> $registry->assertVerified($id,'research',$researchRef,hash('sha256','forged'),$researchStep),
        'wrong Run input digest');
    $unknown=$docStep;$unknown['evidence']['kind']='unknown.write';
    $unknown['execution_binding']='read:unknown.write';
    $reject(static fn()=> $registry->assertVerified($id,'doc',$documentRef,$inputDigest,$unknown),
        'unregistered kind');
    $broken=$docStep;$broken['evidence']['run_id']='previous-run';
    $reject(static fn()=> $registry->assertVerified($id,'doc',$documentRef,$inputDigest,$broken),
        'cross-run replay');
    $state->enabled=false;
    $reject(static fn()=> $registry->assertVerified($id,'research',$researchRef,$inputDigest,$researchStep),
        'disabled domain');
    $state->enabled=true;
    $duplicated=new WorkflowNonActionReceiptRegistry($modules,[$docs,$docs]);
    $reject(static fn()=> $duplicated->assertVerified($id,'doc',$documentRef,$inputDigest,$docStep),
        'ambiguous duplicate ownership');
    $db->executeStatement(
        'UPDATE cos_document_signatures SET status="revoked"
         WHERE organization_id=:org AND signature_id=:sig',['org'=>$org,'sig'=>$sig],
    );
    $reject(static fn()=> $registry->assertVerified($id,'doc',$documentRef,$inputDigest,$docStep),
        'revoked document signature');
    $db->executeStatement(
        'UPDATE cos_document_signatures SET status="signed"
         WHERE organization_id=:org AND signature_id=:sig',['org'=>$org,'sig'=>$sig],
    );
    $db->executeStatement(
        'UPDATE cos_documents SET status="archived"
         WHERE organization_id=:org AND document_id=:doc',['org'=>$org,'doc'=>$document],
    );
    $reject(static fn()=> $registry->assertVerified($id,'doc',$documentRef,$inputDigest,$docStep),
        'archived document');
    $db->executeStatement(
        'UPDATE cos_documents SET status="active"
         WHERE organization_id=:org AND document_id=:doc',['org'=>$org,'doc'=>$document],
    );
    $db->executeStatement(
        'UPDATE tn_capital_market_research_results SET status="INVALIDATED"
         WHERE organization_id=:org AND result_id=:result',['org'=>$org,'result'=>$resultId],
    );
    $reject(static fn()=> $registry->assertVerified($id,'research',$researchRef,$inputDigest,$researchStep),
        'invalidated result');
    $db->executeStatement(
        'UPDATE tn_capital_market_research_results SET status="VALIDATED",record_json=:corrupt
         WHERE organization_id=:org AND result_id=:result',
        ['org'=>$org,'result'=>$resultId,'corrupt'=>json_encode($record+['extra'=>'tamper'],JSON_THROW_ON_ERROR)],
    );
    $reject(static fn()=> $registry->assertVerified($id,'research',$researchRef,$inputDigest,$researchStep),
        'tampered research JSON');
    $db->executeStatement(
        'UPDATE tn_capital_market_research_results SET record_json=:original,
            created_at=DATE_SUB(NOW(6),INTERVAL 1 DAY)
         WHERE organization_id=:org AND result_id=:result',
        ['org'=>$org,'result'=>$resultId,'original'=>json_encode($record,JSON_THROW_ON_ERROR)],
    );
    $reject(static fn()=> $registry->assertVerified($id,'research',$researchRef,$inputDigest,$researchStep),
        'preexisting unrelated result');
    $db->executeStatement(
        'UPDATE tn_capital_market_research_results SET created_at=NOW(6)
         WHERE organization_id=:org AND result_id=:result',['org'=>$org,'result'=>$resultId],
    );
    // Update technical checkpoint ordering to match source timestamp.
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET updated_at=NOW(6)
         WHERE organization_id=:org AND run_id=:run AND step_id="research"',['org'=>$org,'run'=>$run],
    );
    $registry->assertVerified($id,'research',$researchRef,$inputDigest,$researchStep);
    echo "Domain-owned Workflow non-Action MySQL evidence passed: Documents signature, Research validated, tenant, run, input, timing, revocation, module activation, source fingerprint.\n";
} finally {
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_document_signatures WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_documents WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM tn_capital_market_research_results WHERE organization_id=:org',['org'=>$org]);
}
