<?php
declare(strict_types=1);

/** Real MySQL Property identity decision + human review audit evidence. */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowReceiptVerifier;
use App\Persistence\Workflow\FailClosedWorkflowStepClaimAdmission;
use App\Persistence\Workflow\PropertyIdentityResolutionNonActionEvidence;
use App\Persistence\Workflow\WorkflowNonActionReceiptRegistry;
use Doctrine\DBAL\DriverManager;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowRunDraft;
use Platform\Workflow\Model\WorkflowRunIdentity;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),'charset'=>'utf8mb4',
]);
$tag=bin2hex(random_bytes(6));$org='wf-prop-'.$tag;$runId='run-'.$tag;
$id=new WorkflowRunIdentity($org,$runId);
$asset='property-'.$tag;$reviewer='reviewer-'.$tag;
$digest=hash('sha256','property-input-'.$tag);
$actor=new TenantContext(UserId::fromString('manager-'.$tag),
    OrganizationId::fromString($org),OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)]);
$moduleState=new class implements ModuleStateRepositoryInterface{
    public bool $enabled=true;
    public function enabledOverride(string $org,string $module):?bool{return $this->enabled;}
    public function setEnabled(string $org,string $module,bool $enabled):void{$this->enabled=$enabled;}
};
$modules=new ActiveModuleResolver(
    new ModuleCatalog([new ModuleManifest('property','Property','1.0.0')]),$moduleState,
);
$registry=new WorkflowNonActionReceiptRegistry($modules,[
    new PropertyIdentityResolutionNonActionEvidence($db),
]);
$store=new DoctrineWorkflowExecutionStore($db,
    new FailClosedWorkflowReceiptVerifier(),new FailClosedWorkflowStepClaimAdmission());
$deny=static function(callable $fn,string $name):void {
    try{$fn();}catch(DomainException){return;}
    throw new RuntimeException('Untrusted Property identity review accepted: '.$name);
};
$resolutionId=null;$auditId=null;
try {
    $step=['id'=>'verify','capability_id'=>'property.identity.read',
        'capability_version'=>'1.0.0','side_effect_level'=>'none',
        'owner_domain'=>'property','execution_binding'=>'read:property.identity.reviewed.v1',
        'depends_on'=>[],
        'evidence'=>['kind'=>'property.identity.reviewed.v1','run_id'=>$runId,
            'input_digest'=>$digest,'asset_id'=>$asset,
            'reviewer_reference'=>$reviewer,'decision'=>'merge']];
    $def=new WorkflowDefinitionSnapshot('property.identity','1.0.0',[$step]);
    $store->create(new WorkflowRunDraft(
        $id,$def->id,$def->version,$digest,$actor->userId()->value(),
        'corr-'.$tag,definition:$def,
    ),$actor);
    $db->executeStatement(
        'INSERT INTO tn_property_assets (organization_id,asset_id,kind,type_code,lifecycle)
         VALUES (:org,:asset,"house","house","active")',
        ['org'=>$org,'asset'=>$asset],
    );
    $db->executeStatement(
        'INSERT INTO tn_property_identity_resolutions
         (organization_id,submission_id,candidate_asset_id,resolved_asset_id,
          decision,score,signals_json,reason,review_status,reviewer_reference,resolved_at)
         VALUES (:org,NULL,NULL,:asset,"merge",0.95,"{}","reviewed",
                 "resolved",:reviewer,NOW(6))',
        ['org'=>$org,'asset'=>$asset,'reviewer'=>$reviewer],
    );
    $resolutionId=(int)$db->lastInsertId();
    $db->executeStatement(
        'INSERT INTO tn_property_identity_review_audit
         (organization_id,resolution_id,action,resolved_asset_id,reviewer_reference,note)
         VALUES (:org,:resolution,"merge",:asset,:reviewer,"human reviewed")',
        ['org'=>$org,'resolution'=>$resolutionId,'asset'=>$asset,'reviewer'=>$reviewer],
    );
    $auditId=(int)$db->lastInsertId();
    $hash=hash('sha256',implode("\0",[
        (string)$resolutionId,(string)$auditId,$asset,'merge',$reviewer,
    ]));
    $receipt='property:identity:reviewed:v1:'.$resolutionId.':'.$auditId.':'.$hash;
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET state="completed",attempts=1,
         revision=3,result_reference=:ref,updated_at=NOW(6)
         WHERE organization_id=:org AND run_id=:run AND step_id="verify"',
        ['org'=>$org,'run'=>$runId,'ref'=>$receipt],
    );
    $proof=static fn()=>$registry->assertVerified($id,'verify',$receipt,$digest,$step);
    $proof();
    $deny(static fn()=>$registry->assertVerified(new WorkflowRunIdentity('foreign-org',$runId),
        'verify',$receipt,$digest,$step),'foreign tenant');
    $deny(static fn()=>$registry->assertVerified($id,'verify',$receipt,hash('sha256','forged'),$step),
        'changed approved input');
    $moduleState->enabled=false;
    $deny($proof,'disabled Property module');
    $moduleState->enabled=true;
    $db->executeStatement(
        'UPDATE tn_property_identity_resolutions SET review_status="pending"
         WHERE organization_id=:org AND id=:id',['org'=>$org,'id'=>$resolutionId],
    );
    $deny($proof,'pending review is not final');
    $db->executeStatement(
        'UPDATE tn_property_identity_resolutions SET review_status="resolved"
         WHERE organization_id=:org AND id=:id',['org'=>$org,'id'=>$resolutionId],
    );
    $db->executeStatement(
        'UPDATE tn_property_identity_review_audit SET reviewer_reference="tampered"
         WHERE organization_id=:org AND id=:id',['org'=>$org,'id'=>$auditId],
    );
    $deny($proof,'reviewer spoofing');
    $db->executeStatement(
        'UPDATE tn_property_identity_review_audit SET reviewer_reference=:reviewer
         WHERE organization_id=:org AND id=:id',
        ['org'=>$org,'id'=>$auditId,'reviewer'=>$reviewer],
    );
    $db->executeStatement(
        'UPDATE tn_property_identity_resolutions SET resolved_at=DATE_SUB(NOW(6),INTERVAL 1 DAY)
         WHERE organization_id=:org AND id=:id',['org'=>$org,'id'=>$resolutionId],
    );
    $deny($proof,'pre-existing unrelated resolution');
    $db->executeStatement(
        'UPDATE tn_property_identity_resolutions SET resolved_at=NOW(6)
         WHERE organization_id=:org AND id=:id',['org'=>$org,'id'=>$resolutionId],
    );
    $db->executeStatement(
        'UPDATE cos_workflow_steps SET updated_at=NOW(6)
         WHERE organization_id=:org AND run_id=:run AND step_id="verify"',
        ['org'=>$org,'run'=>$runId],
    );
    $proof();
    echo "Property reviewed identity Workflow evidence MySQL passed: resolved asset, human audit, tenant, input, timing, reviewer and module gates.\n";
} finally {
    $db->executeStatement('DELETE FROM tn_property_identity_review_audit WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM tn_property_identity_resolutions WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM tn_property_assets WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_steps WHERE organization_id=:org',['org'=>$org]);
    $db->executeStatement('DELETE FROM cos_workflow_runs WHERE organization_id=:org',['org'=>$org]);
}
