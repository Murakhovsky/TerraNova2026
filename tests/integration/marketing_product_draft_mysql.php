<?php
declare(strict_types=1);
/** Product Management is the only actual catalog/price writer in this real MySQL acceptance. */
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use App\Infrastructure\Marketing\ProductManagementDraftReadAdapter;
use Domains\Marketing\Application\Service\MarketingProductDraftCheckService;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$check=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$denied=static function(callable $fn,string $why):void {
    try{$fn();}catch(DomainException){return;}
    throw new RuntimeException($why);
};
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_DATABASE')?:'cos'),
    getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$token=bin2hex(random_bytes(5));
$org='mkt-product-'.$token;
$foreign='mkt-alien-'.$token;
$disabled='mkt-disabled-'.$token;
$eventStore=new MysqlEventStore($db);
$productOwner=new ProductManagementService(new MySqlProductManagementStore($db,$eventStore));
$pricingOwner=new PricingService($db,$eventStore);
$catalog=new ModuleCatalog([ModuleDefinition::fromArray(require $root.'/app/Domains/ProductManagement/module.php')]);
$state=new class($org,$foreign) implements ModuleStateRepositoryInterface {
    public function __construct(private string $org,private string $foreign){}
    public function enabledOverride(string $organizationId,string $moduleId):?bool {
        return $moduleId==='product_management'&&in_array($organizationId,[$this->org,$this->foreign],true);
    }
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {
        throw new RuntimeException('Read-only module fixture must not allow state mutation.');
    }
};
$port=new ProductManagementDraftReadAdapter($productOwner,$pricingOwner,new ActiveModuleResolver($catalog,$state));
$service=new MarketingProductDraftCheckService($port);
$permissions=[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)];
$actor=static fn(string $org,string $role='manager')=>new TenantContext(UserId::fromString('301'),
    OrganizationId::fromString($org),OrganizationRole::fromString($role),$permissions);
$manager=$actor($org);$otherManager=$actor($foreign);$disabledManager=$actor($disabled);

$created=$productOwner->createProduct($org,[
    'name'=>'Actual Product source readback','sku'=>'MKT'.strtoupper($token),'type'=>'stock'
],'product-mkt-create-'.$token,'ci-product-owner','ci-product-mkt');
$productRef=$created['product_id'];$variantRef=$created['variant_id'];
$source=$productOwner->getProduct($org,$productRef);
$check($source!==null&&$source['status']==='draft'&&count($source['variants'])===1,
    'Native Product Management did not persist original Product/Variant source.');
$before=$service->check($manager,$productRef,$variantRef,'USD');
$check($before['product_status']==='DRAFT'&&$before['variant_status']==='ACTIVE'
    &&$before['can_reference_in_draft']===true
    &&$before['base_price_decimal']===null
    &&!$before['launch_eligible']&&!$before['inventory_verified']
    &&in_array('BASE_PRICE',$before['pending_checks'],true),
    'Marketing created a false advertising readiness or price from a native Product draft.');

$price=$pricingOwner->set($org,$variantRef,'USD','23.750000',
    'product-mkt-price-'.$token,'ci-product-owner','ci-product-mkt');
$checked=$service->check($manager,$productRef,$variantRef,'USD');
$check($checked['source_domain']==='product_management'
    &&$checked['product_ref']===$productRef&&$checked['variant_ref']===$variantRef
    &&$checked['base_price_decimal']==='23.750000'
    &&!in_array('BASE_PRICE',$checked['pending_checks'],true)
    &&!$checked['launch_eligible'],
    'Marketing cross-domain Product readback failed price currency/source checks.');
$unselected=$service->check($manager,$productRef,null,'USD');
$check($unselected['variant_status']==='NOT_SELECTED'
    &&in_array('VARIANT_SELECTION',$unselected['pending_checks'],true),
    'Marketing inferred an unknown selected variant.');
$foreignRef=$service->check($otherManager,$productRef,$variantRef,'USD');
$check($foreignRef['product_status']==='NOT_FOUND'
    &&!$foreignRef['can_reference_in_draft']
    &&$foreignRef['base_price_decimal']===null,
    'Foreign tenant received another tenant Product or its price.');
$denied(static fn()=> $service->check($disabledManager,$productRef,$variantRef,'USD'),
    'Marketing bypassed a disabled Product Management tenant module.');
$denied(static fn()=> $service->check($actor($org,'viewer'),$productRef,$variantRef,'USD'),
    'Marketing viewer read protected Product draft data.');
$other=$productOwner->createProduct($org,[
    'name'=>'Unrelated Product','sku'=>'OTHER'.strtoupper($token),'type'=>'stock'
],'product-mkt-other-'.$token,'ci-product-owner','ci-product-mkt');
$wrongVariant=$service->check($manager,$productRef,$other['variant_id'],'USD');
$check(!$wrongVariant['can_reference_in_draft']&&$wrongVariant['variant_status']==='NOT_ACTIVE'
    &&$wrongVariant['base_price_decimal']===null,
    'Marketing allowed one Product to bind another Product variant or price.');
echo "Marketing M2b native Product Management MySQL cross-domain draft readback, tenant/variant/price checks PASS\n";
