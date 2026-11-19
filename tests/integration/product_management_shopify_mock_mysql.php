<?php
declare(strict_types=1);

/**
 * E4 end-to-end read-only mock against REAL MySQL. No live Shopify network or
 * credentials: fake transport asserts only OAuth and GraphQL read endpoints.
 */
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyHttpTransportInterface;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyReadClient;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyClientCredentials;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyCatalogPullService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;

$pdo=new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),
        (string)(getenv('DB_DATABASE')?:'cos')),
    (string)(getenv('DB_USERNAME')?:'cos'),(string)(getenv('DB_PASSWORD')?:''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC],
);
$token=bin2hex(random_bytes(8));
$org='pm-mock-'.$token;
$shop='pm-ci-mock.myshopify.com';
$sku='SKUMOCK'.strtoupper($token);
putenv('SHOPIFY_SHOP_DOMAIN='.$shop);
putenv('SHOPIFY_CLIENT_ID=ci-client-id');
putenv('SHOPIFY_CLIENT_SECRET=ci-test-secret');
$http=new class($sku) implements ShopifyHttpTransportInterface {
    public int $readCalls=0;
    public int $oauthCalls=0;
    public function __construct(private string $sku) {}
    public function post(string $url,array $headers,string $body):array
    {
        if (str_ends_with($url,'/oauth/access_token')) {
            ++$this->oauthCalls;
            if (!str_contains($body,'grant_type=client_credentials'))
                throw new RuntimeException('Shopify OAuth grant not supplied.');
            return ['status'=>200,'body'=>'{"access_token":"ci-fake-read-token"}'];
        }
        if (!str_ends_with($url,'/admin/api/2026-10/graphql.json'))
            throw new RuntimeException('Unexpected or write-capable Shopify endpoint.');
        $query=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if (!is_string($query['query']??null)
            || !str_starts_with(ltrim($query['query']),'query ')
            || str_contains(strtolower($query['query']),'mutation')
            || ($headers['X-Shopify-Access-Token']??null)!=='ci-fake-read-token') {
            throw new RuntimeException('Shopify GraphQL request must be authenticated read-only.');
        }
        ++$this->readCalls;
        $vars=$query['variables']??[];
        if (str_contains($query['query'],'COSProductVariants')) {
            if (($vars['id']??null)!=='gid://shopify/Product/901'
                ||($vars['after']??null)!=='variant-cursor-1') {
                throw new RuntimeException('Unexpected Shopify Variant cursor.');
            }
            return ['status'=>200,'body'=>json_encode(['data'=>['product'=>[
                'id'=>'gid://shopify/Product/901',
                'variants'=>[
                    'nodes'=>[[
                        'id'=>'gid://shopify/ProductVariant/912',
                        'sku'=>'EXTRA-912','price'=>'9.00',
                        'inventoryItem'=>[
                            'id'=>'gid://shopify/InventoryItem/913',
                            'tracked'=>true,
                            'inventoryLevels'=>[
                                'nodes'=>[[
                                    'location'=>['id'=>'gid://shopify/Location/914','name'=>'Secondary'],
                                    'quantities'=>[['name'=>'on_hand','quantity'=>5],
                                        ['name'=>'available','quantity'=>4]],
                                ]],
                                'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null],
                            ],
                        ],
                    ]],
                    'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null],
                ],
            ]]],JSON_THROW_ON_ERROR)];
        }
        if (str_contains($query['query'],'COSInventoryLevels')) {
            if (($vars['id']??null)!=='gid://shopify/InventoryItem/903'
                ||($vars['after']??null)!=='location-cursor-1') {
                throw new RuntimeException('Unexpected Shopify InventoryItem cursor.');
            }
            return ['status'=>200,'body'=>json_encode(['data'=>['inventoryItem'=>[
                'id'=>'gid://shopify/InventoryItem/903',
                'inventoryLevels'=>[
                    'nodes'=>[[
                        'location'=>['id'=>'gid://shopify/Location/915','name'=>'Overflow'],
                        'quantities'=>[['name'=>'on_hand','quantity'=>3],
                            ['name'=>'available','quantity'=>2]],
                    ]],
                    'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null],
                ],
            ]]],JSON_THROW_ON_ERROR)];
        }
        $record=[
            'id'=>'gid://shopify/Product/901',
            'title'=>'Observed Remote Product',
            'status'=>'ACTIVE',
            'variants'=>[
                'nodes'=>[[
                    'id'=>'gid://shopify/ProductVariant/902',
                    'sku'=>$this->sku,'barcode'=>null,
                    'inventoryItem'=>[
                        'id'=>'gid://shopify/InventoryItem/903',
                        'tracked'=>true,
                        'inventoryLevels'=>[
                            'nodes'=>[[
                                'location'=>['id'=>'gid://shopify/Location/904','name'=>'Fixture'],
                                'quantities'=>[
                                    ['name'=>'on_hand','quantity'=>9],
                                    ['name'=>'available','quantity'=>7],
                                ],
                            ]],
                            'pageInfo'=>['hasNextPage'=>true,'endCursor'=>'location-cursor-1'],
                        ],
                    ],
                ]],
                'pageInfo'=>['hasNextPage'=>true,'endCursor'=>'variant-cursor-1'],
            ],
        ];
        return [
            'status'=>200,
            'body'=>json_encode(['data'=>['products'=>[
                'nodes'=>[$record],
                'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null],
            ]]],JSON_THROW_ON_ERROR),
        ];
    }
};
$observations=new ShopifyObservationStore($pdo);
$id=$observations->provision($org,$shop,'shopify_env_primary');
$products=new ProductManagementService(new MySqlProductManagementStore($pdo,new MysqlEventStore($pdo)));
$state=new class implements ModuleStateRepositoryInterface {
    public bool $enabled=false;
    public function enabledOverride(string $org,string $moduleId):?bool {
        return $moduleId==='product_management'?$this->enabled:null;
    }
    public function setEnabled(string $org,string $moduleId,bool $enabled):void {
        if ($moduleId==='product_management') $this->enabled=$enabled;
    }
};
$catalog=new ModuleCatalog((new ModuleDiscovery(dirname(__DIR__,2).'/app/Domains'))->discover());
$modules=new ActiveModuleResolver($catalog,$state);
$pull=new ShopifyCatalogPullService(
    $observations,new ShopifyReadClient($http),new \Domains\ProductManagement\Infrastructure\Shopify\ShopifyNestedConnectionReader(new ShopifyReadClient($http)),
    new ShopifyClientCredentials($http),$products,$modules
);
$assert=static function(bool $value,string $message):void {
    if (!$value)throw new RuntimeException('Shopify mock MySQL: '.$message);
};
$denied=false;
try { $pull->pull($org,$id,1); }
catch (DomainException) { $denied=true; }
$assert($denied && $http->readCalls===0 && $http->oauthCalls===0,
    'inactive tenant module cannot initiate even a read-only import');
$state->setEnabled($org,'product_management',true);
$held=$observations->start($org,$id);
$blocked=false;
try { $observations->start($org,$id); }
catch (DomainException) { $blocked=true; }
$assert($blocked,'Concurrent Shopify run cannot take the same connection lease');
$observations->fail($org,$held,'ci_lease_release');
$stale=false;
try { $observations->checkpoint($org,$id,$held,'old-cursor',1,0,false); }
catch (DomainException) { $stale=true; }
$assert($stale,'Stale run cannot overwrite the active cursor');
$first=$pull->pull($org,$id,1);
$assert($first['products_observed']===1 && $first['conflicts']===1
    && $first['status']==='completed','remote unknown Product must be quarantined after observation');
$status=$observations->status($org,$id);
$assert($status['open_conflicts']===1 && $status['cursor_pending']===false,
    'Sync result must expose conflict and completed pagination');
$assert($products->searchProducts($org,$sku)===[],'Shopify pull must never create canonical Product');
$stmt=$pdo->prepare('SELECT payload_json FROM pm_external_product_snapshots WHERE organization_id=? AND connection_id=? AND product_gid=?');
$stmt->execute([$org,$id,'gid://shopify/Product/901']);
$stored=$stmt->fetchColumn();
$assert(is_string($stored) && str_contains($stored,'Observed Remote Product'),'provider snapshot persisted');
$stmt=$pdo->prepare('SELECT on_hand,available FROM pm_external_stock_observations WHERE organization_id=? AND connection_id=? AND variant_gid=? AND location_gid=?');
$stmt->execute([$org,$id,'gid://shopify/ProductVariant/902','gid://shopify/Location/904']);
$stock=$stmt->fetch(PDO::FETCH_ASSOC);
$assert(is_array($stock) && (string)$stock['on_hand']==='9.000000'
    && (string)$stock['available']==='7.000000','external stock observation recorded exactly');
$extraStock=$pdo->prepare('SELECT COUNT(*) FROM pm_external_stock_observations WHERE organization_id=? AND connection_id=?');
$extraStock->execute([$org,$id]);
$assert((int)$extraStock->fetchColumn()===3,'Nested Shopify pagination must persist all three stock locations');
$expanded=json_decode($stored,true,flags:JSON_THROW_ON_ERROR);
$assert(count($expanded['variants']['nodes'])===2
    && $expanded['variants']['pageInfo']['hasNextPage']===false,
    'Saved Shopify Product must include fully resolved variant pages');
$second=$pull->pull($org,$id,1);
$assert($second['products_observed']===1 && $http->readCalls===6 && $http->oauthCalls===2,
    'Two explicitly requested sync passes should issue only read calls');
$assert($observations->status($org,$id)['open_conflicts']===1,
    'Repeated remote observations cannot multiply identical open conflicts');
$assert($products->searchProducts($org,$sku)===[],'Second sync cannot silently create canonical state');
echo "Shopify E4 read-only GraphQL → MySQL snapshots/conflicts acceptance passed.\n";
