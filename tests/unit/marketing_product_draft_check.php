<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use Domains\Marketing\Application\Contract\MarketingProductDraftReadPort;
use Domains\Marketing\Application\Service\MarketingProductDraftCheckService;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\CapabilityContract;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$assert=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$deny=static function(callable $fn,string $why):void {
    try{$fn();}catch(DomainException){return;}
    throw new RuntimeException($why);
};
$permissions=[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)];
$actor=static fn(string $org,string $role='manager',array $permissions=[]):TenantContext=>
    new TenantContext(UserId::fromString('123'),OrganizationId::fromString($org),
        OrganizationRole::fromString($role),$permissions);
$manager=$actor('product-marketing-tenant','manager',$permissions);
$foreign=$actor('alien-tenant','manager',$permissions);
$viewer=$actor('product-marketing-tenant','viewer',$permissions);
$productId=str_repeat('a',32);
$variantId=str_repeat('b',32);

$source=new class($productId,$variantId) implements MarketingProductDraftReadPort {
    public bool $forge=false;
    public string $status='draft';
    public string $variantStatus='active';
    public ?string $price='17.250000';
    public function __construct(public string $productId,public string $variantId){}
    public function search(string $org,string $query,int $limit,int $offset):array {
        if($org!=='product-marketing-tenant')throw new DomainException('Foreign tenant must not access Product.');
        return ['items'=>[['product_ref'=>$this->forge?'bad':$this->productId,'variant_ref'=>$this->variantId,
            'name'=>'Local Product','sku'=>'LOCAL-SKU','product_status'=>$this->status]],'has_more'=>false];
    }
    public function stock(string $org,string $productId,string $variantId,string $locationId):?array {
        if($org!=='product-marketing-tenant')throw new DomainException('Foreign stock denied.');
        return ['variant_id'=>$this->forge?'bad':$variantId,'location_id'=>$locationId,
            'on_hand'=>'8.000000','reserved'=>'3.000000','available'=>'5.000000','revision'=>1];
    }
    public function inspect(string $org,string $productId,?string $variantId,string $currency):?array {
        if($org!=='product-marketing-tenant')throw new DomainException('Foreign tenant must not access Product.');
        if($productId!==$this->productId)return null;
        return [
            'product_ref'=>$this->forge?str_repeat('c',32):$productId,
            'variant_ref'=>$variantId,
            'currency'=>$currency,
            'product_status'=>$this->status,
            'variant_status'=>$variantId===null?'not_selected':($variantId===$this->variantId?$this->variantStatus:'not_found'),
            'product_revision'=>3,
            'variant_revision'=>2,
            'base_price_decimal'=>$variantId===$this->variantId?$this->price:null
        ];
    }
};
$service=new MarketingProductDraftCheckService($source);
$page=$service->search($manager,'LOCAL',20,0);
$assert(count($page['items'])===1&&$page['items'][0]['variant_ref']===$variantId
    &&$page['items'][0]['launch_eligible']===false,'Product selector must expose draft-only references.');
$deny(static fn()=> $service->search($viewer),'Viewer bypassed Product selection permissions.');
$deny(static fn()=> $service->search($foreign),'Other tenant accessed Product search.');
$deny(static fn()=> $service->search($manager,'',21),'Unbounded Product page accepted.');
$source->forge=true;
$deny(static fn()=> $service->search($manager),'Malformed Product source search trusted.');
$source->forge=false;
$ready=$service->check($manager,$productId,$variantId,'USD');
$assert($ready['product_status']==='DRAFT'
    &&$ready['variant_status']==='ACTIVE'
    &&$ready['base_price_decimal']==='17.250000'
    &&$ready['source_domain']==='product_management'
    &&$ready['can_reference_in_draft']===true
    &&$ready['launch_eligible']===false
    &&$ready['inventory_verified']===false
    &&$ready['external_destination_verified']===false
    &&$ready['provider_ad_policy_verified']===false,
    'Native Product draft readback must not claim Meta launch readiness.');
$loc=str_repeat('f',32);
$stock=$service->check($manager,$productId,$variantId,'USD',$loc,'https://example.com/item');
$assert($stock['stock_observation']['status']==='AVAILABLE_OBSERVED'
    &&$stock['stock_observation']['available']==='5.000000'
    &&$stock['destination_syntax_status']==='VALID_HTTPS_SYNTAX_ONLY'
    &&!$stock['launch_eligible']&&!$stock['external_destination_verified']&&!$stock['inventory_verified'],
    'Stock/URL observation must never claim launch readiness.');
$deny(static fn()=> $service->check($manager,$productId,null,'USD',$loc),'Location without variant accepted.');
$deny(static fn()=> $service->check($manager,$productId,$variantId,'USD',$loc,'https://127.0.0.1/'),'IP destination accepted.');
$none=$service->check($manager,str_repeat('d',32),null,'USD');
$assert(!$none['can_reference_in_draft']&&$none['product_status']==='NOT_FOUND',
    'Unknown Product was wrongly accepted for a Creative draft.');
$missingVariant=$service->check($manager,$productId,str_repeat('e',32),'USD');
$assert(!$missingVariant['can_reference_in_draft']
    &&$missingVariant['variant_status']==='NOT_ACTIVE',
    'Unknown Product variant cannot be advertised as a verified SKU.');
$source->status='archived';
$archived=$service->check($manager,$productId,$variantId,'USD');
$assert(!$archived['can_reference_in_draft']&&$archived['product_status']==='NOT_ACTIVE',
    'Archived Product was accepted for new creative planning.');
$source->status='active';$source->price=null;
$unpriced=$service->check($manager,$productId,$variantId,'USD');
$assert($unpriced['can_reference_in_draft']
    &&in_array('BASE_PRICE',$unpriced['pending_checks'],true)
    &&!$unpriced['launch_eligible'],
    'Missing Product price must remain a pending check, not a fabricated price.');
$source->forge=true;
$deny(static fn()=> $service->check($manager,$productId,$variantId,'USD'),
    'Mismatched owner Product ID was trusted by Marketing.');
$source->forge=false;
$deny(static fn()=> $service->check($foreign,$productId,$variantId,'USD'),
    'Other tenant accessed Product through Marketing.');
$deny(static fn()=> $service->check($viewer,$productId,$variantId,'USD'),
    'Viewer bypassed Marketing Product read permissions.');
$deny(static fn()=> $service->check($manager,'other',null,'USD'),
    'Foreign Product identifier form was accepted.');
$deny(static fn()=> $service->check($manager,$productId,null,'usd'),
    'Currency without ISO uppercase format accepted.');

$manifest=require $root.'/app/Domains/Marketing/module.php';
$raw=array_values(array_filter($manifest['contributions']['capability_contracts'],
    static fn($c)=>$c['id']==='marketing.product.draft_check.get'));
$assert(count($raw)===1,'Product Draft Query not registered exactly once.');
$c=CapabilityContract::fromArray($raw[0]);
$assert($c->kind==='query'&&$c->sideEffectLevel==='none'
    &&$c->approvalPolicy==='none'&&$c->executionBinding==='query:marketing.product.draft_check.get',
    'Marketing Product Draft Query must remain read-only under the canonical Gateway.');
(new CapabilityJsonInputValidator())->validate($c,['product_id'=>$productId,'variant_id'=>$variantId,'currency'=>'USD']);
$deny(static fn()=> (new CapabilityJsonInputValidator())->validate($c,[
    'product_id'=>$productId,'currency'=>'USD','organization_id'=>'alien']),
    'Client can forge Product tenant in canonical Query.');
$selector=array_values(array_filter($manifest['contributions']['capability_contracts'],
    static fn($c)=>$c['id']==='marketing.product.selector.search'));
$assert(count($selector)===1,'Product selector Query missing.');
$sc=CapabilityContract::fromArray($selector[0]);
$assert($sc->kind==='query'&&$sc->sideEffectLevel==='none','Product selector must be read-only.');
(new CapabilityJsonInputValidator())->validate($sc,['q'=>'LOCAL','limit'=>20,'offset'=>0]);
$deny(static fn()=> (new CapabilityJsonInputValidator())->validate($sc,['organization_id'=>'alien']),
    'Caller supplied a forged selector tenant.');
$adapter=(string)file_get_contents($root.'/symfony/src/Infrastructure/Marketing/ProductManagementDraftReadAdapter.php');
$assert(str_contains($adapter,"isEnabled(\$organizationId,'product_management')")
    &&str_contains($adapter,'$this->products->getProduct($organizationId,$productId)')
    &&str_contains($adapter,'$this->prices->get($organizationId,$variantId,$currency)')
    &&!preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b/i',$adapter),
    'Product cross-domain adapter must respect module activation and use only Product services.');
echo "Marketing M2b Product tenant-gated source readback, draft-only eligibility and schema fences PASS\n";
