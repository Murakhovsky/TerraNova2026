<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\ProductManagement\Application\Contract\ProductMediaBrowserPort;
use Domains\ProductManagement\Infrastructure\Shopify\{ShopifyOutboundPlanFactory,ShopifyOutboundMedia,ShopifyStagedImageUploadPort,ShopifyStagedImageUploader,ShopifyHttpTransportInterface,ShopifyReadClient};

$assert=static function(bool $test,string $why):void{if(!$test)throw new RuntimeException($why);};
$deny=static function(callable $fn):void {try{$fn();}catch(DomainException){return;}throw new RuntimeException('Unsafe plan not rejected.');};
$org='shopify-ci';$conn=str_repeat('c',32);$pid=str_repeat('p',32);
$pid=str_repeat('a',32);$a=str_repeat('b',32);$b=str_repeat('d',32);
$native=['product_id'=>$pid,'name'=>'Draft','description'=>'A&B','status'=>'draft','revision'=>1,'variants'=>[
    ['variant_id'=>$a,'sku'=>'SKU-A','status'=>'active','revision'=>1,'options_json'=>'{"size":"large","finish":"matte"}'],
    ['variant_id'=>$b,'sku'=>'SKU-B','status'=>'active','revision'=>2,'options_json'=>'{"size":"small","finish":"gloss"}']
]];
$prices=[$a=>['currency'=>'USD','price'=>'350.00','revision'=>1],$b=>['currency'=>'USD','price'=>'520.25','revision'=>1]];
$plan=ShopifyOutboundPlanFactory::catalogDraft($org,$conn,$native,$prices,'USD');
$i=$plan['variables']['input'];
$assert($i['status']==='DRAFT'&&count($i['productOptions'])===2&&count($i['variants'])===2&&!isset($i['variants'][0]['inventoryQuantities']),'Complete pinned Shopify productSet list, no stock write');
$nodes=[];foreach($i['variants'] as $index=>$v)$nodes[]=['id'=>'gid://shopify/ProductVariant/'.($index+1),'sku'=>$v['sku'],'price'=>$v['price'],
    'selectedOptions'=>array_map(static fn($x)=>['name'=>$x['optionName'],'value'=>$x['name']],$v['optionValues'])];
$remote=['id'=>'gid://shopify/Product/1','handle'=>$i['handle'],'status'=>'DRAFT','title'=>$i['title'],'descriptionHtml'=>$i['descriptionHtml'],'variants'=>['nodes'=>array_reverse($nodes),'pageInfo'=>['hasNextPage'=>false]]];
$verified=ShopifyOutboundPlanFactory::verifyCatalogReadback($remote,$plan);
$assert($verified['variants']===[$a=>'gid://shopify/ProductVariant/1',$b=>'gid://shopify/ProductVariant/2'],'Mapping independent of response order');
$bad=$remote;$bad['variants']['nodes'][0]['price']='1.00';
$deny(fn()=>ShopifyOutboundPlanFactory::verifyCatalogReadback($bad,$plan));
$bad=$remote;$bad['variants']['pageInfo']['hasNextPage']=true;
$deny(fn()=>ShopifyOutboundPlanFactory::verifyCatalogReadback($bad,$plan));
$deny(fn()=>ShopifyOutboundPlanFactory::catalogDraft($org,$conn,$native,[$a=>$prices[$a]],'USD'));
$deny(fn()=>ShopifyOutboundPlanFactory::catalogDraft($org,$conn,$native,$prices,'UAH'));
$deny(fn()=>ShopifyOutboundPlanFactory::catalogDraft($org,$conn,$native,$prices,'USD',true));
$concept=$native;$concept['status']='active';$concept['variants'][0]['sku']='SERIES01-L01';
$deny(fn()=>ShopifyOutboundPlanFactory::catalogDraft($org,$conn,$concept,$prices,'USD',true));
$bytes='mock-verified-image';$checksum=hash('sha256',$bytes);$mediaId=str_repeat('f',32);
$media=new class($bytes) implements ProductMediaBrowserPort{
    public function __construct(private string $bytes){}
    public function candidates(string $organizationId,string $query,int $limit,int $offset):array{return [];}
    public function preview(string $organizationId,string $fileId):array{
        if($organizationId!=='shopify-ci'||$fileId!=='native-file')throw new DomainException('Foreign image.');
        return ['mime_type'=>'image/png','bytes'=>$this->bytes];
    }
};
$upload=new class implements ShopifyStagedImageUploadPort{
    public int $calls=0;
    public function upload(string $url,array $parameters,string $bytes,string $mime,string $filename):void{
        if($url!=='https://shopify-staged-uploads.storage.googleapis.com/'||$mime!=='image/png'
            ||$bytes!=='mock-verified-image'||$parameters!==[['name'=>'key','value'=>'mock-key']])
            throw new RuntimeException('Upload not pinned to staging target.');
        ++$this->calls;
    }
};
$http=new class implements ShopifyHttpTransportInterface{
    public int $mutations=0;
    public function post(string $url,array $headers,string $body):array{
        $r=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if(str_contains($r['query'],'COSStageImage')){
            ++$this->mutations;
            $d=['stagedUploadsCreate'=>['userErrors'=>[],'stagedTargets'=>[['url'=>'https://shopify-staged-uploads.storage.googleapis.com/','resourceUrl'=>'https://shopify-staged-uploads.storage.googleapis.com/staged','parameters'=>[['name'=>'key','value'=>'mock-key']]]]]];
        }elseif(str_contains($r['query'],'COSCreateImage')){
            ++$this->mutations;
            $d=['fileCreate'=>['userErrors'=>[],'files'=>[['id'=>'gid://shopify/MediaImage/9','fileStatus'=>'UPLOADED']]]];
        }elseif(str_contains($r['query'],'COSImageReady')){
            $d=['node'=>['id'=>'gid://shopify/MediaImage/9','fileStatus'=>'READY']];
        }else throw new RuntimeException('Unknown request.');
        return ['status'=>200,'body'=>json_encode(['data'=>$d],JSON_THROW_ON_ERROR)];
    }
};
$service=new ShopifyOutboundMedia($media,$http,new ShopifyReadClient($http),$upload);
$manifest=$service->preview($org,[['media_id'=>$mediaId,'file_id'=>'native-file','checksum'=>$checksum,'variant_id'=>null,'role'=>'cover','sort_order'=>0]]);
$assert($http->mutations===0&&$upload->calls===0,'Preview cannot write');
$assert($service->create($org,'ci.myshopify.com','token',$mediaId,$manifest[$mediaId])==='gid://shopify/MediaImage/9'&&$upload->calls===1&&$http->mutations===2,'Staged upload and independent file READY');
$deny(fn()=>$service->preview('foreign',[['media_id'=>$mediaId,'file_id'=>'native-file','checksum'=>$checksum]]));
$deny(fn()=>(new ShopifyStagedImageUploader())->upload('https://example.org/',[],$bytes,'image/png','cos-'.$checksum.'.png'));
echo "Shopify catalog and native media request/readback contracts PASS.\n";
