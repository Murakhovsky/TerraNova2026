<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Domains\ProductManagement\Application\Contract\ProductMediaBrowserPort;

/** Only verified native Documents images; staged upload and fileCreate are
 * called after the durable approved Action claim. File identity is reused on
 * resync, and an ambiguous upload is never automatically retried. */
final readonly class ShopifyOutboundMedia
{
    public function __construct(private ProductMediaBrowserPort $media,private ShopifyHttpTransportInterface $http,
        private ShopifyReadClient $reader,private ShopifyStagedImageUploadPort $uploader){}
    public function preview(string $org,array $rows):array
    {
        if(count($rows)>10)throw new DomainException('Shopify writeback supports at most ten images.');
        $result=[];
        foreach($rows as $row){
            $id=$row['media_id']??null;$file=$row['file_id']??null;$sum=$row['checksum']??null;
            if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||isset($result[$id])
                ||!is_string($file)||!is_string($sum)||!preg_match('/^[a-f0-9]{64}$/D',$sum))
                throw new DomainException('Verified Documents media identity required.');
            $image=$this->media->preview($org,$file);
            if(!hash_equals($sum,hash('sha256',$image['bytes'])))throw new DomainException('Native media bytes changed.');
            $result[$id]=['file_id'=>$file,'checksum'=>$sum,'mime'=>$image['mime_type'],'size'=>strlen($image['bytes']),
                'variant_id'=>$row['variant_id'],'role'=>$row['role'],'sort_order'=>(int)$row['sort_order']];
        }
        return $result;
    }
    public function create(string $org,string $host,string $token,string $mediaId,array $expected):string
    {
        $image=$this->media->preview($org,$expected['file_id']);
        if(!hash_equals($expected['checksum'],hash('sha256',$image['bytes']))||$image['mime_type']!==$expected['mime']
            ||strlen($image['bytes'])!==$expected['size'])throw new DomainException('Approved image changed.');
        $extension=match($expected['mime']){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',default=>throw new DomainException('Unsupported image.')};
        $filename='cos-'.hash('sha256',$org.':'.$mediaId.':'.$expected['checksum']).'.'.$extension;
        $staged=$this->mutation($host,$token,'mutation COSStageImage($input: [StagedUploadInput!]!) { stagedUploadsCreate(input: $input) { stagedTargets { url resourceUrl parameters { name value } } userErrors { field message } } }',
            ['input'=>[['resource'=>'IMAGE','filename'=>$filename,'mimeType'=>$expected['mime'],'httpMethod'=>'POST']]],'stagedUploadsCreate');
        $targets=$staged['stagedTargets']??null;
        if(!is_array($targets)||count($targets)!==1||!is_string($targets[0]['url']??null)
            ||!is_string($targets[0]['resourceUrl']??null)||!is_array($targets[0]['parameters']??null))
            throw new DomainException('Incomplete staged upload response.');
        $this->uploader->upload($targets[0]['url'],$targets[0]['parameters'],$image['bytes'],$expected['mime'],$filename);
        $file=$this->mutation($host,$token,'mutation COSCreateImage($files: [FileCreateInput!]!) { fileCreate(files: $files) { files { id fileStatus } userErrors { field message } } }',
            ['files'=>[['originalSource'=>$targets[0]['resourceUrl'],'filename'=>$filename,'contentType'=>'IMAGE','duplicateResolutionMode'=>'RAISE_ERROR']]],'fileCreate');
        $gid=$file['files'][0]['id']??null;
        if(!is_string($gid)||count($file['files'])!==1||!preg_match('#^gid://shopify/MediaImage/[0-9]+$#D',$gid))
            throw new DomainException('Provider file identity missing.');
        $this->ready($host,$token,$gid);
        return $gid;
    }
    public function ready(string $host,string $token,string $gid):void
    {
        if(!preg_match('#^gid://shopify/MediaImage/[0-9]+$#D',$gid))throw new DomainException('Invalid provider media identity.');
        for($i=0;$i<5;$i++){
            $file=$this->reader->query($host,$token,'query COSImageReady($id: ID!) { node(id: $id) { ... on MediaImage { id fileStatus } } }',['id'=>$gid])['data']['node']??null;
            if(($file['id']??null)!==$gid)throw new DomainException('Media readback identity differs.');
            if(($file['fileStatus']??null)==='READY')return;
            if(($file['fileStatus']??null)==='FAILED')break;
            if($i<4)sleep(2);
        }
        throw new DomainException('Provider file not independently READY; reconcile without replay.');
    }
    private function mutation(string $host,string $token,string $query,array $variables,string $key):array
    {
        ShopifyReadClient::assertShopDomain($host);
        $response=$this->http->post('https://'.$host.'/admin/api/'.ShopifyReadClient::API_VERSION.'/graphql.json',
            ['Content-Type'=>'application/json','X-Shopify-Access-Token'=>$token],json_encode(['query'=>$query,'variables'=>$variables],JSON_THROW_ON_ERROR));
        $data=json_decode($response['body'],true,flags:JSON_THROW_ON_ERROR);
        $result=$data['data'][$key]??null;
        if($response['status']!==200||!empty($data['errors'])||!is_array($result)||($result['userErrors']??null)!==[])
            throw new DomainException('Shopify media mutation unconfirmed.');
        return $result;
    }
}
