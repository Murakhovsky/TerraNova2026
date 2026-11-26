<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use Domains\Finance\Application\Contract\MarketingSpendEvidenceReadPort;
use Domains\Finance\Application\Contract\FinanceMarketingSpendActionIntentRepositoryInterface;
use Domains\Finance\Application\Contract\FinanceSpendObservationRepositoryInterface;
use Domains\Finance\Application\Service\ApprovedFinanceMarketingSpendImportOperations;
use Domains\Finance\Application\Service\FinanceSpendObservationService;
use Domains\Finance\Automation\Action\FinanceMarketingSpendImportActionHandler;
use Domains\Finance\Domain\FinanceSpendObservationPolicy;
use Kernel\Action\Action;
use Kernel\Module\CapabilityContract;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$expect=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$manifest=require $root.'/app/Domains/Finance/module.php';
$typed=[];
foreach($manifest['contributions']['capability_contracts'] as $contract){
    $item=CapabilityContract::fromArray($contract);
    $typed[$item->id]=$item;
}
$c=$typed[FinanceMarketingSpendImportActionHandler::IMPORT]??null;
$expect($c instanceof CapabilityContract&&$c->kind==='command'
    &&$c->ownerDomain==='finance'&&$c->executionBinding==='action:'.FinanceMarketingSpendImportActionHandler::IMPORT
    &&$c->sideEffectLevel==='internal'&&$c->approvalPolicy==='required'
    &&$c->idempotency==='required'&&$manifest['enabled_by_default']===false,
    'Finance Marketing source Action must be canonical, explicit-approval and disabled by default.');
$input=[
    'ad_account_ref'=>'mads-'.str_repeat('a',24),
    'source_snapshot_ref'=>'mis-'.str_repeat('b',24),
    'expected_source_revision'=>1,
    'expected_source_hash'=>str_repeat('c',64)
];
$envelope=static fn(array $parameters):array=>['target_type'=>'marketing_spend_snapshot','target_id'=>$input['source_snapshot_ref'],'parameters'=>$parameters];
(new CapabilityJsonInputValidator())->validate($c,$envelope($input));
foreach([
    [...$input,'organization_id'=>'alien'],
    [...$input,'reported_spend_decimal'=>'999999'],
    [...$input,'expected_source_revision'=>1.2],
    [...$input,'expected_source_hash'=>'short'],
] as $bad){
    try{(new CapabilityJsonInputValidator())->validate($c,$envelope($bad));}
    catch(DomainException){continue;}
    throw new RuntimeException('Source Action schema allowed an unapproved or forged amount/scope.');
}
// The unit/native-ledger tests inject a narrowly scoped admission fixture.
// The real Workflow/Action admission is independently exercised in the canonical E2E.
$admission=new class implements \Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface {
    public bool $allowed=true;
    public function assertAuthorized(Action $action):void {
        if(!$this->allowed)throw new DomainException('Fixture admission denied.');
    }
};
$moduleCatalog=new \Kernel\Module\ModuleCatalog([
    \Kernel\Module\ModuleDefinition::fromArray(require $root.'/app/Domains/Finance/module.php'),
    \Kernel\Module\ModuleDefinition::fromArray(require $root.'/app/Domains/Marketing/module.php')
]);
$states=new class implements \Kernel\Module\Contract\ModuleStateRepositoryInterface {
    public function enabledOverride(string $org,string $moduleId):?bool{return true;}
    public function setEnabled(string $org,string $moduleId,bool $enabled):void{}
};
$modules=new \Kernel\Module\ActiveModuleResolver($moduleCatalog,$states);
$identities=new class implements \Kernel\Identity\Contract\IdentityResolverInterface {
    public function resolve(\Kernel\Shared\Domain\UserId $userId,\Kernel\Shared\Domain\OrganizationId $organizationId):?\Kernel\Identity\Model\AuthenticatedIdentity {
        return new \Kernel\Identity\Model\AuthenticatedIdentity($userId,$organizationId,\Kernel\Identity\Model\OrganizationRole::fromString('manager'));
    }
};
$tenants=new \Kernel\Tenant\Service\TenantContextFactory(new class implements \Kernel\Tenant\Contract\TenantPermissionResolverInterface {
    public function permissionsFor(\Kernel\Identity\Model\AuthenticatedIdentity $identity):array {
        return [\Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::ACCESS),
            \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::MANAGE)];
    }
});
$keyFor=static fn(string $key):string=>str_starts_with($key,'wf:')?$key:'wf:'.hash('sha256',$key);

$financePolicy=new FinanceSpendObservationPolicy();
$source=new class implements MarketingSpendEvidenceReadPort {
    public int $reads=0;
    public int $revision=1;
    public string $hash;
    public function __construct(){ $this->hash=str_repeat('c',64); }
    public function get(\Kernel\Tenant\Model\TenantContext $actor,string $account,string $snapshot):array
    {
        $org=$actor->organizationId()->value();
        ++$this->reads;
        if($org!=='tenant-a')throw new DomainException('Other tenant source denied.');
        return [
            'ad_account_ref'=>$account,'source_snapshot_ref'=>$snapshot,'grain'=>'campaign',
            'external_id'=>'99001','window_start'=>'2026-11-01','window_end'=>'2026-11-07',
            'timezone'=>'Europe/Kyiv','currency'=>'USD',
            'attribution_hash'=>str_repeat('d',64),'source_hash'=>$this->hash,
            'source_revision'=>$this->revision,'source_status'=>$this->revision===1?'PROVISIONAL':'REVISED',
            'reported_spend_decimal'=>$this->revision===1?'10.750000':'20.250000'
        ];
    }
};
$ledger=new class($financePolicy) implements FinanceSpendObservationRepositoryInterface {
    public int $writes=0;
    public function __construct(private FinanceSpendObservationPolicy $policy){}
    public function record(string $org,array $raw,string $correlation):array
    {
        ++$this->writes;
        $e=$this->policy->validate($raw);
        return [...$e,'id'=>'fins-'.str_repeat('e',24),
            'evidence_verification'=>'UNVERIFIED_SOURCE','finance_expense_confirmed'=>false];
    }
    public function reconciliationStatus(string $org,array $scope):array{return [];}
};
$intents=new class implements FinanceMarketingSpendActionIntentRepositoryInterface {
    public array $rows=[];
    public function claim(string $org,string $key,string $account,string $snapshot,int $rev,string $hash):array
    {
        $k=$org.':'.$key;
        $prev=$this->rows[$k]??null;
        if($prev!==null){
            if($prev['account']!==$account||$prev['snapshot']!==$snapshot
                ||$prev['rev']!==$rev||$prev['hash']!==$hash) {
                throw new DomainException('Idempotency collision.');
            }
            return ['state'=>$prev['state'],'finance_observation_id'=>$prev['id']];
        }
        $this->rows[$k]=['account'=>$account,'snapshot'=>$snapshot,'rev'=>$rev,'hash'=>$hash,
            'state'=>'CLAIMED','id'=>null];
        return ['state'=>'CLAIMED','finance_observation_id'=>null];
    }
    public function complete(string $org,string $key,string $observationId,int $revision,string $hash):void
    {
        $k=$org.':'.$key;
        if(($this->rows[$k]['rev']??null)!==$revision||($this->rows[$k]['hash']??null)!==$hash){
            throw new DomainException('Completed source mismatch.');
        }
        $this->rows[$k]['state']='SUCCEEDED';$this->rows[$k]['id']=$observationId;
    }
};
$operations=new ApprovedFinanceMarketingSpendImportOperations($source,
    new FinanceSpendObservationService($ledger,$financePolicy),$intents,$admission,$modules);
$handler=new FinanceMarketingSpendImportActionHandler($operations,$identities,$tenants);
$make=static fn(array $data,string $key,string $tenant='tenant-a',string $target='mis-'.'bbbbbbbbbbbbbbbbbbbbbbbb')=>
    new Action(
        'act-'.substr(hash('sha256',$key),0,24),$tenant,
        FinanceMarketingSpendImportActionHandler::IMPORT,
        'marketing_spend_snapshot',$target,$data,
        'USER','301','APPROVAL_REQUIRED','MEDIUM',$keyFor($key),new DateTimeImmutable('2026-11-20T12:00:00+00:00'),
        status:\Kernel\Action\ActionStatus::Running,
        correlationId:'finance-approve-trace-01'
    );
$admission->allowed=false;
$expect(!$handler->execute($make($input,'finance-evidence-action-denied'))->successful&&$intents->rows===[]&&$ledger->writes===0,'Denied canonical admission wrote Finance evidence.');
$admission->allowed=true;
$proposed=$make($input,'finance-evidence-proposed');$proposed->status=\Kernel\Action\ActionStatus::Proposed;
$expect(!$handler->execute($proposed)->successful&&$intents->rows===[],'Unclaimed Action wrote Finance evidence.');
$first=$handler->execute($make($input,'finance-evidence-action-001'));
$expect($first->successful&&$first->data['status']==='SOURCE_OBSERVATION_UNRECONCILED'
    &&$first->data['finance_expense_confirmed']===false
    &&$first->data['budget_mutated']===false
    &&$first->data['idempotent_replay']===false&&$ledger->writes===1,
    'Approved Finance import did not issue bounded source-only receipt.');
$source->revision=2;$source->hash=str_repeat('f',64);
$retry=$handler->execute($make($input,'finance-evidence-action-001'));
$expect($retry->successful&&$retry->data['idempotent_replay']===true
    &&$retry->data['source_revision']===1&&$ledger->writes===1&&$source->reads===1,
    'After source revision changed a completed Action must replay its frozen receipt, not re-import.');
$changed=$handler->execute($make($input,'finance-evidence-action-002'));
$expect(!$changed->successful&&$ledger->writes===1,
    'An approval for stale version was promoted to a corrected provider spend value.');
$mismatched=$handler->execute($make([...$input,'expected_source_hash'=>str_repeat('f',64)],
    'finance-evidence-action-001'));
$expect(!$mismatched->successful&&$ledger->writes===1,
    'Same action idempotency key was repurposed for another source hash.');
$badTarget=$handler->execute($make($input,'finance-evidence-action-003',target:'mis-'.str_repeat('e',24)));
$expect(!$badTarget->successful&&$ledger->writes===1,
    'Forged source target escaped Action preflight.');
$foreign=$handler->execute($make($input,'finance-evidence-action-004','tenant-b'));
$expect(!$foreign->successful&&$ledger->writes===1,'Cross-tenant Action source readback accepted.');
$expect(!$handler->supports('marketing.paid.meta.campaign.activate'),
    'Finance evidence Action handler accidentally supports Meta paid writes.');
echo "Finance approved Marketing spend Action contracts, tenant fencing, hash pinning, idempotent replay PASS\n";
