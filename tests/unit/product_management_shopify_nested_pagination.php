<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyNestedConnectionReader;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyReadClient;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyHttpTransportInterface;

$variant=[
    'id'=>'gid://shopify/ProductVariant/1002','sku'=>'SKU-1002',
    'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/1003','inventoryLevels'=>[
        'nodes'=>[['location'=>['id'=>'gid://shopify/Location/1004'],
            'quantities'=>[['name'=>'on_hand','quantity'=>2]]]],
        'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null],
    ]],
];
$initial=[
    'id'=>'gid://shopify/Product/1001',
    'variants'=>[
        'nodes'=>[[
            'id'=>'gid://shopify/ProductVariant/1005','sku'=>'SKU-1005',
            'inventoryItem'=>['id'=>'gid://shopify/InventoryItem/1006','inventoryLevels'=>[
                'nodes'=>[['location'=>['id'=>'gid://shopify/Location/1007'],
                    'quantities'=>[['name'=>'on_hand','quantity'=>9]]]],
                'pageInfo'=>['hasNextPage'=>true,'endCursor'=>'level-next'],
            ]],
        ]],
        'pageInfo'=>['hasNextPage'=>true,'endCursor'=>'variant-next'],
    ],
];
$http=new class($variant) implements ShopifyHttpTransportInterface {
    public int $calls=0;
    public bool $repeat=false;
    public bool $badItem=false;
    public function __construct(private array $variant) {}
    public function post(string $url,array $headers,string $body):array {
        $request=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if(!str_ends_with($url,'/admin/api/2026-10/graphql.json')
            ||!str_starts_with($request['query']??'','query ')
            ||str_contains($request['query']??'','mutation')) {
            throw new RuntimeException('Nested pages must be authorized GraphQL read queries.');
        }
        ++$this->calls;
        $vars=$request['variables'];
        if(str_contains($request['query'],'COSProductVariants')){
            if(($vars['id']??null)!=='gid://shopify/Product/1001'
                ||($vars['after']??null)!=='variant-next') {
                throw new RuntimeException('Wrong Product variant continuation.');
            }
            $data=['data'=>['product'=>[
                'id'=>'gid://shopify/Product/1001',
                'variants'=>['nodes'=>[$this->variant],
                    'pageInfo'=>['hasNextPage'=>$this->repeat,
                        'endCursor'=>$this->repeat?'variant-next':null]],
            ]]];
        }else{
            if(($vars['id']??null)!=='gid://shopify/InventoryItem/1006'
                ||($vars['after']??null)!=='level-next') {
                throw new RuntimeException('Wrong InventoryLevel continuation.');
            }
            $data=['data'=>['inventoryItem'=>[
                'id'=>$this->badItem?'gid://shopify/InventoryItem/9999':'gid://shopify/InventoryItem/1006',
                'inventoryLevels'=>[
                    'nodes'=>[['location'=>['id'=>'gid://shopify/Location/1008'],
                        'quantities'=>[['name'=>'on_hand','quantity'=>7]]]],
                    'pageInfo'=>['hasNextPage'=>false,'endCursor'=>null],
                ],
            ]]];
        }
        return ['status'=>200,'body'=>json_encode($data,JSON_THROW_ON_ERROR)];
    }
};
$reader=new ShopifyNestedConnectionReader(new ShopifyReadClient($http));
$complete=$reader->expand('ci-store.myshopify.com','test-token',$initial);
if(count($complete['variants']['nodes'])!==2
    ||count($complete['variants']['nodes'][0]['inventoryItem']['inventoryLevels']['nodes'])!==2
    ||$complete['variants']['pageInfo']['hasNextPage']!==false
    ||$http->calls!==2){
    throw new RuntimeException('Nested Shopify variant/location connection was not completely resolved.');
}
$deny=static function(callable $action):void {
    try{$action();}catch(DomainException){return;}
    throw new RuntimeException('Incomplete Shopify pagination incorrectly accepted.');
};
$http->repeat=true;
$deny(static fn()=>$reader->expand('ci-store.myshopify.com','test-token',$initial));
$http->repeat=false;
$http->badItem=true;
$deny(static fn()=>$reader->expand('ci-store.myshopify.com','test-token',$initial));
$http->badItem=false;
$noCursor=$initial;
unset($noCursor['variants']['pageInfo']['endCursor']);
$deny(static fn()=>$reader->expand('ci-store.myshopify.com','test-token',$noCursor));
echo "Product Management Shopify nested GraphQL pagination and rejection tests passed.\n";
