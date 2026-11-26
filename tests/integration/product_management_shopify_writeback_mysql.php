<?php
declare(strict_types=1);
require (string)(getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Infrastructure\Shopify\{ShopifyHttpTransportInterface,ShopifyReadClient,ShopifyClientCredentials,ShopifyAccessReadiness,ShopifyObservationStore,ShopifyDraftWritebackService,ShopifyChannelReadback,ShopifySeries01NativeAudit,ShopifyOutboundMedia,ShopifyStagedImageUploadPort};
use Domains\ProductManagement\Application\Contract\ProductMediaBrowserPort;
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
    public array $collections=[];
    public array $files=[];
    public array $publications=[];
    public int $fileWrites=0;
    public int $publishWrites=0;
    public function __construct(private string $shop){}
    public function post(string $url,array $headers,string $body):array
    {
        if($url==='https://'.$this->shop.'/admin/oauth/access_token')return ['status'=>200,'body'=>'{"access_token":"mock-token"}'];
        if($url!=='https://'.$this->shop.'/admin/api/2026-10/graphql.json'||($headers['X-Shopify-Access-Token']??null)!=='mock-token')
            throw new RuntimeException('Unexpected provider boundary.');
        $req=json_decode($body,true,flags:JSON_THROW_ON_ERROR);$q=$req['query'];$v=$req['variables'];
        if(str_contains($q,'COSAccessReadback'))$data=['products'=>['nodes'=>[],'pageInfo'=>['hasNextPage'=>false]]];
        elseif(str_contains($q,'COSAccess'))$data=['shop'=>['myshopifyDomain'=>$this->shop,'currencyCode'=>$this->currency],
            'currentAppInstallation'=>['accessScopes'=>[['handle'=>'write_products'],['handle'=>'read_orders'],['handle'=>'write_files'],['handle'=>'write_publications']]]];
        elseif(str_contains($q,'COSDraftLookup'))$data=['product'=>$this->remote[$v['identifier']['handle']]??null];
        elseif(str_contains($q,'COSDraftById')){
            $p=null;foreach($this->remote as $r)if($r['id']===$v['id'])$p=$r;$data=['product'=>$p];
        }
        elseif(str_contains($q,'COSStageImage'))$data=['stagedUploadsCreate'=>['userErrors'=>[],'stagedTargets'=>[['url'=>'https://shopify-staged-uploads.storage.googleapis.com/','resourceUrl'=>'https://shopify-staged-uploads.storage.googleapis.com/mock','parameters'=>[['name'=>'key','value'=>'mock']]]]]];
        elseif(str_contains($q,'COSCreateImage')){$this->fileWrites++;$data=['fileCreate'=>['userErrors'=>[],'files'=>[['id'=>'gid://shopify/MediaImage/777','fileStatus'=>'READY']]]];}
        elseif(str_contains($q,'COSImageReady'))$data=['node'=>['id'=>$v['id'],'fileStatus'=>'READY']];
        elseif(str_contains($q,'COSPublicationFence')){
            $data=['product'=>['id'=>$v['id'],'resourcePublicationsV2'=>['nodes'=>array_map(static fn($id)=>['publication'=>['id'=>$id],'isPublished'=>true],$this->publications[$v['id']]??[]),'pageInfo'=>['hasNextPage'=>false]]]];
        }elseif(str_contains($q,'COSPublicationReadback'))$data=['product'=>['id'=>$v['id'],'status'=>'ACTIVE','publishedOnPublication'=>in_array($v['publication'],$this->publications[$v['id']]??[],true)]];
        elseif(str_contains($q,'COSPublication'))$data=['node'=>['id'=>$v['id']]];
        elseif(str_contains($q,'COSPublishProduct')){
            $this->publishWrites++;$this->publications[$v['id']][]=$v['input'][0]['publicationId'];
            $data=['publishablePublish'=>['userErrors'=>[]]];
        }
        elseif(str_contains($q,'COSCollection'))$data=['collection'=>['id'=>$v['id']]];
        elseif(str_contains($q,'COSSkuLookup')){
            $nodes=[];foreach($this->remote as $r)foreach($r['variants']['nodes'] as $variant)
                if($v['query']==='sku:"'.$variant['sku'].'"')$nodes[]=['id'=>$variant['id'],'sku'=>$variant['sku']];
            $data=['productVariants'=>['nodes'=>$nodes,'pageInfo'=>['hasNextPage'=>false]]];
        }
        elseif(str_contains($q,'COSChannelProduct')){
            $p=null;foreach($this->remote as $r)if($r['id']===$v['id'])$p=$r;
            if($p!==null){
                foreach(['media','collections','resourcePublicationsV2'] as $field)$p[$field]=['nodes'=>[],'pageInfo'=>['hasNextPage'=>false]];
                $p['media']['nodes']=array_map(static fn($id)=>['id'=>$id],$this->files[$p['id']]??[]);
                $p['collections']['nodes']=array_map(static fn($id)=>['id'=>$id],$this->collections[$p['id']]??[]);
            }
            $data=['product'=>$p];
        }elseif(str_contains($q,'mutation COSProductSet')){
            if(!in_array($v['input']['status']??null,['DRAFT','ACTIVE'],true))throw new RuntimeException('Invalid productSet.');
            ++$this->writes;$i=$v['input'];$n=$this->writes;
            $old=$this->remote[$i['handle']]??null;
            if($old!==null&&($v['identifier']['id']??null)!==$old['id'])throw new RuntimeException('Update requires mapped GID.');
            if($old===null&&isset($v['identifier']))throw new RuntimeException('Must create without identifier.');
            $nodes=[];
            foreach($i['variants'] as $index=>$variant){
                $nodes[]=['id'=>$variant['id']??'gid://shopify/ProductVariant/'.(990+$n*10+$index),
                    'sku'=>$variant['sku'],'price'=>$variant['price'],'selectedOptions'=>array_map(static fn($o)=>['name'=>$o['optionName'],'value'=>$o['name']],$variant['optionValues'])];
            }
            $p=['id'=>$old['id']??'gid://shopify/Product/'.(900+$n),'title'=>$i['title'],'handle'=>$i['handle'],'status'=>$i['status'],
                'descriptionHtml'=>$i['descriptionHtml'],'variants'=>['nodes'=>$nodes,'pageInfo'=>['hasNextPage'=>false]]];
            $this->remote[$i['handle']]=$p;
            if(isset($i['files']))$this->files[$p['id']]=array_column($i['files'],'id');
            if(isset($i['collections']))$this->collections[$p['id']]=$i['collections'];
            if($this->timeout)return ['status'=>503,'body'=>'{}'];
            $data=['productSet'=>['product'=>['id'=>$p['id']],'userErrors'=>[]]];
        }else throw new RuntimeException('Unexpected provider query.');
        return ['status'=>200,'body'=>json_encode(['data'=>$data],JSON_THROW_ON_ERROR)];
    }
};
$events=new MysqlEventStore($pdo);$products=new ProductManagementService(new MySqlProductManagementStore($pdo,$events));$prices=new PricingService($pdo,$events);
$obs=new ShopifyObservationStore($pdo);$conn=$obs->provision($org,$shop,'shopify_env_primary','observation_only');
$reader=new ShopifyReadClient($http);$credentials=new ShopifyClientCredentials($http);$access=new ShopifyAccessReadiness($reader);
$imageSource=new class($org) implements ProductMediaBrowserPort{
    public function __construct(private string $org){}
    public function candidates(string $organizationId,string $query,int $limit,int $offset):array{return [];}
    public function preview(string $organizationId,string $fileId):array{
        if($organizationId!==$this->org||$fileId!=='native-media')throw new DomainException('Foreign media.');
        return ['mime_type'=>'image/png','bytes'=>'mock-native-image'];
    }
};
$upload=new class implements ShopifyStagedImageUploadPort{
    public int $calls=0;
    public function upload(string $url,array $parameters,string $bytes,string $mime,string $filename):void{
        if($bytes!=='mock-native-image'||$mime!=='image/png')throw new RuntimeException('Wrong image bytes.');
        ++$this->calls;
    }
};
$writer=new ShopifyDraftWritebackService($pdo,$obs,$credentials,$reader,$access,$http,$products,$prices,new ShopifyOutboundMedia($imageSource,$http,$reader,$upload));
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
// Generic multi-variant canonical draft, per-SKU exact prices and preserved GIDs on update.
$multi=$create('GENERIC-A','multi');$mid=$multi['product_id'];
$second=$products->createVariant($org,$mid,'GENERIC-B','pcs',false,'multi-variant','42','qa-writeback');
$prices->set($org,$second['variant_id'],'USD','510.25','multi-second-price','42','qa-writeback');
$collection=['gid://shopify/Collection/123'];
$plan=$writer->preview($org,$conn,$mid,$collection);$hash=ShopifyDraftWritebackService::digest($plan);
$assert(count($plan['variables']['input']['variants'])===2,'Multi-variant preview');
$first=$writer->apply($org,$conn,$mid,$hash,'wf:'.str_repeat('1',64),$collection);
$assert(count($first['variants'])===2&&$writer->audit($org,$conn,$mid)['independent_verified'],'Multi-variant mapping and collection readback');
$products->updateProduct($org,$mid,['name'=>'Updated draft'],1,'multi-edit','42','qa-writeback');
$hash2=ShopifyDraftWritebackService::digest($writer->preview($org,$conn,$mid,$collection));
$update=$writer->apply($org,$conn,$mid,$hash2,'wf:'.str_repeat('2',64),$collection);
$assert($update['product_gid']===$first['product_gid']&&$update['variants']===$first['variants']&&$http->writes===4,'Pinned update must preserve identities');
$assert($writer->audit($org,$conn,$mid)['independent_verified'],'Updated native price and SKU evidence');
$deny(fn()=>$writer->apply($org,$conn,$mid,$hash2,'wf:'.str_repeat('3',64),$collection));
$deny(fn()=>$writer->preview($org,$conn,$pid,[],'gid://shopify/Publication/123'));
$assert($access->verifyCatalogRead($shop,'mock-token')['catalog_read_verified'],'Live-style provider sample readback');
$assert($http->writes===4,'Replay or concept publication made a provider effect.');
// Explicit pinned resync with the same native revision reuses both provider product and variants.
$resync=str_repeat('f',32);
$hash3=ShopifyDraftWritebackService::digest($writer->preview($org,$conn,$mid,$collection,null,$resync));
$resynced=$writer->apply($org,$conn,$mid,$hash3,'wf:'.str_repeat('4',64),$collection,null,$resync);
$assert($resynced['product_gid']===$first['product_gid']&&$resynced['variants']===$first['variants']&&$http->writes===5,'Explicit resync duplicated provider identity.');
// Native media reference fixture, verified source port and staged uploads; no provider upload during preview.
$mediaId=bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO pm_media_refs (organization_id,media_id,product_id,storage_ref,source_file_id,checksum,role,sort_order,status) VALUES (?,?,?,?,?,?,?,0,'active')")
    ->execute([$org,$mediaId,$mid,'fixture-storage','native-media',hash('sha256','mock-native-image'),'cover']);
$hash4=ShopifyDraftWritebackService::digest($writer->preview($org,$conn,$mid,$collection));
$assert($http->fileWrites===0,'Preview uploaded media.');
$withImage=$writer->apply($org,$conn,$mid,$hash4,'wf:'.str_repeat('5',64),$collection);
$assert($withImage['media'][$mediaId]==='gid://shopify/MediaImage/777'&&$http->fileWrites===1&&$upload->calls===1&&$writer->audit($org,$conn,$mid)['independent_verified'],'Native image mapping not verified.');
// Only simulate an independently released native item in this disposable fixture;
// the real application does not grant publication authority through SQL.
$pdo->prepare("UPDATE pm_products SET status='active',revision=revision+1 WHERE organization_id=? AND product_id=?")->execute([$org,$mid]);
$publication='gid://shopify/Publication/456';
$pubDigest=ShopifyDraftWritebackService::digest($writer->preview($org,$conn,$mid,$collection,$publication));
putenv('COS_SHOPIFY_PUBLICATION_ENABLED=0');
$deny(fn()=>$writer->apply($org,$conn,$mid,$pubDigest,'wf:'.str_repeat('6',64),$collection,$publication));
putenv('COS_SHOPIFY_PUBLICATION_ENABLED=1');
$published=$writer->apply($org,$conn,$mid,$pubDigest,'wf:'.str_repeat('6',64),$collection,$publication);
$assert($published['publication_gid']===$publication&&$http->publishWrites===1&&$http->fileWrites===1&&$writer->audit($org,$conn,$mid)['independent_verified'],'Publication/readback or media identity reuse failed.');
$deny(fn()=>$writer->apply($org,$conn,$mid,$pubDigest,'wf:'.str_repeat('7',64),$collection,$publication));
$assert($http->publishWrites===1,'Publication replay made a provider effect.');
putenv('COS_SHOPIFY_PUBLICATION_ENABLED');
putenv('COS_SHOPIFY_WRITEBACK_ENABLED');
echo "Shopify approved draft writeback, provider scope, currency, mapping and ambiguous effect fence PASS.\n";
