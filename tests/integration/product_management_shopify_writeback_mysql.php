<?php
declare(strict_types=1);
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Infrastructure\Shopify\{ShopifyHttpTransportInterface,ShopifyReadClient,ShopifyClientCredentials,ShopifyAccessReadiness,ShopifyObservationStore,ShopifyDraftWritebackService,ShopifyChannelReadback,ShopifySeries01NativeAudit};
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Domains\ProductManagement\Application\Service\{ProductManagementService,PricingService};
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;

$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',(string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),(string)(getenv('DB_DATABASE')?:'cos')),(string)(getenv('DB_USERNAME')?:'cos'),(string)(getenv('DB_PASSWORD')?:''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$tag=bin2hex(random_bytes(6));$org='pm-writeback-'.$tag;$shop='cos-ci-'.$tag.'.myshopify.com';
putenv('SHOPIFY_SHOP_DOMAIN='.$shop);putenv('SHOPIFY_CLIENT_ID=mock-id');putenv('SHOPIFY_CLIENT_SECRET=mock-secret');
$http=new class($shop) implements ShopifyHttpTransportInterface {
    public string $currency='USD';
    public int $writes=0;
    public bool $timeout=false;
    public array $remote=[];
    public function __construct(private string $shop){}
    public function post(string $url,array $headers,string $body):array
    {
        if($url==='https://'.$this->shop.'/admin/oauth/access_token')return ['status'=>200,'body'=>'{"access_token":"mock-token"}'];
        if($url!=='https://'.$this->shop.'/admin/api/2026-10/graphql.json'||($headers['X-Shopify-Access-Token']??null)!=='mock-token')
            throw new RuntimeException('Unexpected provider boundary.');
        $req=json_decode($body,true,flags:JSON_THROW_ON_ERROR);$q=$req['query'];$v=$req['variables'];
        if(str_contains($q,'COSAccess'))$data=['shop'=>['myshopifyDomain'=>$this->shop,'currencyCode'=>$this->currency],
            'currentAppInstallation'=>['accessScopes'=>[['handle'=>'write_products'],['handle'=>'read_orders']]]];
        elseif(str_contains($q,'COSDraftLookup'))$data=['product'=>$this->remote[$v['identifier']['handle']]??null];
        elseif(str_contains($q,'COSSkuLookup'))$data=['productVariants'=>['nodes'=>[],'pageInfo'=>['hasNextPage'=>false]]];
        elseif(str_contains($q,'COSChannelProduct')){
            $p=null;foreach($this->remote as $r)if($r['id']===$v['id'])$p=$r;
            if($p!==null)foreach(['media','collections','resourcePublicationsV2'] as $field)$p[$field]=['nodes'=>[],'pageInfo'=>['hasNextPage'=>false]];
            $data=['product'=>$p];
        }elseif(str_contains($q,'mutation COSProductSet')){
            if(isset($v['identifier'])||($v['input']['status']??null)!=='DRAFT')throw new RuntimeException('Must create draft only.');
            ++$this->writes;$i=$v['input'];$n=$this->writes;
            $p=['id'=>'gid://shopify/Product/'.(900+$n),'title'=>$i['title'],'handle'=>$i['handle'],'status'=>'DRAFT',
                'variants'=>['nodes'=>[['id'=>'gid://shopify/ProductVariant/'.(990+$n),'sku'=>$i['variants'][0]['sku'],'price'=>rtrim(rtrim($i['variants'][0]['price'],'0'),'.')]],'pageInfo'=>['hasNextPage'=>false]]];
            $this->remote[$i['handle']]=$p;
            if($this->timeout)return ['status'=>503,'body'=>'{}'];
            $data=['productSet'=>['product'=>['id'=>$p['id']],'userErrors'=>[]]];
        }else throw new RuntimeException('Unexpected provider query.');
        return ['status'=>200,'body'=>json_encode(['data'=>$data],JSON_THROW_ON_ERROR)];
    }
};
$events=new MysqlEventStore($pdo);$products=new ProductManagementService(new MySqlProductManagementStore($pdo,$events));$prices=new PricingService($pdo,$events);
$obs=new ShopifyObservationStore($pdo);$conn=$obs->provision($org,$shop,'shopify_env_primary','observation_only');
$reader=new ShopifyReadClient($http);$credentials=new ShopifyClientCredentials($http);$access=new ShopifyAccessReadiness($reader);
$writer=new ShopifyDraftWritebackService($pdo,$obs,$credentials,$reader,$access,$http,$products,$prices);
$assert=static function(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);};
$deny=static function(callable $fn):void{try{$fn();}catch(Throwable){return;}throw new RuntimeException('Expected fail-closed operation.');};
$create=function(string $sku,string $key)use($products,$prices,$org):array{
    $p=$products->createProduct($org,['name'=>'Draft '.$sku,'sku'=>$sku,'type'=>'stock','track_inventory'=>false],$key,'42','qa-writeback');
    $prices->set($org,$p['variant_id'],'USD','390.00',$key.'-price','42','qa-writeback');return $p;
};
$p=$create('SERIES01-L01','draft-one');$pid=$p['product_id'];$preview=$writer->preview($org,$conn,$pid);$digest=ShopifyDraftWritebackService::digest($preview);
$key='wf:'.str_repeat('a',64);
putenv('COS_SHOPIFY_WRITEBACK_ENABLED=0');$deny(fn()=>$writer->apply($org,$conn,$pid,$digest,$key));
putenv('COS_SHOPIFY_WRITEBACK_ENABLED=1');$deny(fn()=>$writer->apply($org,$conn,$pid,str_repeat('b',64),$key));
$http->currency='UAH';$deny(fn()=>$writer->apply($org,$conn,$pid,$digest,$key));$http->currency='USD';
$assert($http->writes===0,'Denied requests must never mutate.');
$result=$writer->apply($org,$conn,$pid,$digest,$key);
$assert($result['state']==='verified'&&$http->writes===1&&$writer->audit($org,$conn,$pid)['independent_verified'],'Draft and mapping evidence.');
$deny(fn()=>$writer->apply($org,$conn,$pid,$digest,$key));
$deny(fn()=>$writer->apply('foreign',$conn,$pid,$digest,'wf:'.str_repeat('c',64)));
$assert($http->writes===1,'Replay or cross-tenant mutation.');
$channel=(new ShopifyChannelReadback($obs,$credentials,$reader,$access))->inspect($org,$conn,$result['product_gid']);
$assert($channel['complete']&&$channel['canonical_product_id']===$pid&&!$channel['provider_write_authorized'],'Channel evidence must be read-only.');
$native=(new ShopifySeries01NativeAudit($pdo))->inspect($org);
$assert($native['verified_native_identities']===1&&!$native['all_nine_present']&&!$native['approved_import_proven'],'Native audit must not invent approval.');
$p2=$create('SERIES01-L02','draft-two');$id2=$p2['product_id'];$digest2=ShopifyDraftWritebackService::digest($writer->preview($org,$conn,$id2));
$key2='wf:'.str_repeat('d',64);$http->timeout=true;
$deny(fn()=>$writer->apply($org,$conn,$id2,$digest2,$key2));$http->timeout=false;
$audit=$writer->audit($org,$conn,$id2);
$assert($audit['state']==='uncertain'&&$audit['remote_present']&&!$audit['independent_verified'],'Timeout after remote effect quarantined.');
$deny(fn()=>$writer->apply($org,$conn,$id2,$digest2,'wf:'.str_repeat('e',64)));
$assert($http->writes===2,'Unknown effect must not replay.');
putenv('COS_SHOPIFY_WRITEBACK_ENABLED');
echo "Shopify approved draft writeback, provider scope, currency, mapping and ambiguous effect fence PASS.\n";
