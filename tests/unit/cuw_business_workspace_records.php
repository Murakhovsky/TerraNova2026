<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Provider\FinanceWebProvider;
use App\Web\Experience\Extension\Provider\MarketingWebProvider;
use App\Web\Experience\Extension\Provider\ProductManagementWebProvider;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Search\SearchResultMatcher;
use App\Web\Experience\Unified\Business\WorkspaceCapabilityRead;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class) use($root):void {
    if (!str_starts_with($class,'App\\')) return;
    $candidate=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if (is_file($candidate)) require $candidate;
});
$definitions=[];
foreach (glob($root.'/app/Domains/*/module.php') as $path) $definitions[]=ModuleDefinition::fromArray(require $path);
$manifest=new ModuleCatalog($definitions);
$catalog=new CanonicalCapabilityCatalog($manifest);
$state=new class implements ModuleStateRepositoryInterface {
    public array $disabled=[];
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return !in_array($moduleId,$this->disabled,true);}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{}
};
$modules=new ActiveModuleResolver($manifest,$state);
$bindings=new FederationCapabilityBindingResolver($catalog,new DomainModuleRegistry([]),$modules);
$actor=static fn(string $org,string $role='manager'):TenantContext=>new TenantContext(
    UserId::fromString('15'),OrganizationId::fromString($org),OrganizationRole::fromString($role),
    [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]
);
$tenants=new class implements TenantContextProviderInterface {
    public ?TenantContext $actor=null;
    public function current():?TenantContext{return $this->actor;}
};
$tenants->actor=$actor('tenant-a');
$ctx=new WebExtensionContext('tenant-a','manager');
$id=str_repeat('a',32);
$campaign='mkt-'.str_repeat('b',24);
$budget='finb-'.str_repeat('c',24);
$fixtures=[
    'query:product_management.product.search'=>['items'=>array_map(static fn(int $i):array=>[
        'product_id'=>str_pad(dechex($i+1),32,'0',STR_PAD_LEFT),'name'=>'Product '.$i,'sku'=>'SKU-'.$i,'status'=>'active','variant_id'=>str_pad(dechex($i+50),32,'0',STR_PAD_LEFT),
    ],range(0,20))],
    'query:product_management.product.get'=>['found'=>true,'product'=>['product_id'=>$id,'name'=>'Product A','status'=>'active','product_type'=>'stock','variants'=>[['sku'=>'A-1','status'=>'active','uom'=>'pcs','secret'=>'never expose']]]],
    'query:marketing.campaign.search'=>['items'=>[['id'=>$campaign,'name'=>'Campaign A','status'=>'DRAFT']]],
    'query:marketing.campaign.get'=>['campaign'=>['id'=>$campaign,'name'=>'Campaign A','status'=>'DRAFT','revision'=>2]],
    'query:finance.budget.envelope.list'=>['items'=>[['id'=>$budget,'name'=>'Budget A','state'=>'ACTIVE','currency'=>'EUR']],'has_more'=>true],
    'query:sales.order.list'=>['items'=>[['order_id'=>$id,'status'=>'placed','channel'=>'native','total'=>'12.00','currency'=>'EUR']],'has_more'=>false,'scope'=>'self'],
    'query:finance.budget.envelope.get'=>['envelope'=>['id'=>$budget,'name'=>'Budget A','state'=>'ACTIVE','currency'=>'EUR','limit_minor'=>1000,'available_minor'=>800]],
    'query:finance.budget.reservation.list'=>['items'=>[['id'=>'reservation','state'=>'HELD','amount_minor'=>200]]],
    'query:sales.order.read'=>['order'=>['order_id'=>$id,'status'=>'placed']],
    'query:finance.order.financial_evidence.read'=>['evidence'=>null],
    'query:finance.order.payment.operations.read'=>['order_id'=>$id,'operations'=>[['operation_id'=>'pay-op','kind'=>'capture','amount'=>'12.00','state'=>'pending_registration']],'has_more'=>false],
    'command:finance.budget.envelope.draft.create'=>['id'=>$budget,'state'=>'DRAFT','revision'=>1],
    'command:property.asset.create'=>['asset_id'=>'must-not-run'],
];
$adapters=[];
foreach($fixtures as $binding=>$result) {
    $adapters[$binding]=new class($binding,$result) implements CapabilityInvocationAdapterInterface{
        public int $calls=0;
        public array $last=[];
        public function __construct(private string $name,private array $fixture){}
        public function binding():string{return $this->name;}
        public function invoke(TenantContext $actor,array $input,string $key,string $trace):mixed{
            ++$this->calls;$this->last=$input;
            if($actor->organizationId()->value()!=='tenant-a')throw new DomainException('Record unavailable.');
            return $this->fixture;
        }
    };
}
$gateway=new CanonicalCapabilityGateway($catalog,$modules,$bindings,array_values($adapters));
$read=new WorkspaceCapabilityRead($gateway,$tenants);
$pm=new ProductManagementWebProvider(new SearchResultMatcher(),$read);
$marketing=new MarketingWebProvider(new SearchResultMatcher(),$read);
$finance=new FinanceWebProvider(new SearchResultMatcher(),$read);
if(!$finance instanceof \App\Web\Experience\Extension\Contract\WorkspaceProviderInterface)throw new RuntimeException('Finance workspace must implement the registered Workspace contract.');
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$denied=static function(callable $run):void{
    try{$run();}catch(DomainException){return;}
    throw new RuntimeException('Unauthorized workspace read reached adapter.');
};
$draftContext=new WebExtensionContext('tenant-a','manager','workspace','workspaces','finance-budget-draft');
$check($finance->actions($ctx)===[]&&$finance->actions($draftContext,new EntityRef('finance.budget',$budget))===[],'Budget draft command escaped its dedicated form context.');
$draftActions=$finance->actions($draftContext);
$check(count($draftActions)===1&&$draftActions[0]->command==='finance.budget.envelope.draft.create'&&$draftActions[0]->enabled,'Missing canonical Finance draft UIAction.');
$check(count($finance->extensions($ctx,'finance.budget'))===1&&$finance->extensions($ctx,'finance.payments')===[],'Finance form link escaped its Workspace.');
$check($adapters['command:finance.budget.envelope.draft.create']->calls===0,'Presentation dispatched a Finance draft.');
$page=$pm->records($ctx,'pm.catalog','',2);
$check(count($page['items'])===20&&$page['has_more']&&$adapters['query:product_management.product.search']->last['offset']===20,'Native pagination lost.');
$entity=new EntityRef('product_management.product',$id);
$detail=$pm->record($ctx,'pm.catalog',$entity);
$check($detail['hit']->entity->key()===$entity->key()&&!str_contains(json_encode($detail,JSON_THROW_ON_ERROR),'secret'),'Typed, bounded Product detail missing.');
$check($pm->record($ctx,'pm.catalog',new EntityRef('marketing.campaign',$campaign))===null,'Cross-type detail escaped.');
$check($pm->record($ctx,'pm.catalog',new EntityRef('product_management.product',str_repeat('f',32)))===null,'Mismatched native readback escaped.');
$check(count($marketing->records($ctx,'marketing.campaigns','',2)['items'])===1
    &&$adapters['query:marketing.campaign.search']->last['offset']===20,'Campaign list pagination missing.');
$check($marketing->record($ctx,'marketing.campaigns',new EntityRef('marketing.campaign',$campaign))['facts']!==[],'Campaign detail missing.');
$budgetPage=$finance->records($ctx,'finance.budget','',2);
$check(count($budgetPage['items'])===1&&$budgetPage['has_more']&&$adapters['query:finance.budget.envelope.list']->last['offset']===20,'Budget list missing.');
$paymentsPage=$finance->records($ctx,'finance.payments','',1);
$check(count($paymentsPage['items'])===1&&$paymentsPage['items'][0]->entity->key()==='finance.order_payment:'.$id
    &&$adapters['query:sales.order.list']->last['scope']==='auto','Finance payment list bypassed Sales scope.');
$check($finance->record($ctx,'finance.budget',new EntityRef('finance.budget',$budget))['tables'][0]['rows'][0][2]==='200','Budget reservation evidence missing.');
$money=$finance->record($ctx,'finance.payments',new EntityRef('finance.order_payment',$id));
$check(str_contains($money['facts'][0]['value'],'no confirmed collection')&&in_array('pending_registration',$money['tables'][0]['rows'][0],true),'Pending receipt confused with confirmed money.');
$tenants->actor=$actor('tenant-b');
$denied(static fn()=>$pm->record($ctx,'pm.catalog',$entity));
$denied(static fn()=>$pm->record(new WebExtensionContext('tenant-b','manager'),'pm.catalog',$entity));
$tenants->actor=$actor('tenant-a','viewer');
$denied(static fn()=>$pm->records(new WebExtensionContext('tenant-a','viewer'),'pm.catalog','',1));
$tenants->actor=$actor('tenant-a');
$state->disabled=['finance'];
$denied(static fn()=>$finance->records($ctx,'finance.budget','',1));
$state->disabled=['sales'];
$denied(static fn()=>$finance->records($ctx,'finance.payments','',1));
$state->disabled=['product_management'];
$denied(static fn()=>$pm->records($ctx,'pm.catalog','',1));
$state->disabled=[];
$denied(static fn()=>$read->query($ctx,'property.asset.create',['type_code'=>'house','city'=>'Lviv']));
$check($adapters['command:property.asset.create']->calls===0,'Read projection invoked a mutation.');
$check($finance->link($ctx,new EntityRef('finance.budget','bad'))===null,'Invalid entity link accepted.');
echo "CUW-E workspace read projections, tenant fences and no-write boundary PASS.\n";
