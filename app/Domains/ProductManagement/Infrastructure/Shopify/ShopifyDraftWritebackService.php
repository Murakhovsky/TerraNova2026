<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\PricingService;
use PDO;
use Throwable;

/** COS-owned catalog only. Each independently approved revision has an immutable
 * one-shot claim; a pending or uncertain claim fences the entire product.
 * Updates address verified GIDs, never an unrelated handle/SKU match.
 */
final readonly class ShopifyDraftWritebackService
{
    private const LOOKUP='query COSDraftLookup($identifier: ProductIdentifierInput!) { product: productByIdentifier(identifier: $identifier) { id handle status title descriptionHtml variants(first: 10) { nodes { id sku price selectedOptions { name value } } pageInfo { hasNextPage } } } }';
    private const BY_ID='query COSDraftById($id: ID!) { product(id: $id) { id handle status title descriptionHtml variants(first: 10) { nodes { id sku price selectedOptions { name value } } pageInfo { hasNextPage } } } }';
    private const SKU_LOOKUP='query COSSkuLookup($query: String!) { productVariants(first: 10, query: $query) { nodes { id sku } pageInfo { hasNextPage } } }';
    public function __construct(private PDO $connection,private ShopifyObservationStore $observations,
        private ShopifyClientCredentials $credentials,private ShopifyReadClient $reader,
        private ShopifyAccessReadiness $access,private ShopifyHttpTransportInterface $http,
        private ProductManagementService $products,private PricingService $prices,private ?ShopifyOutboundMedia $media=null) {}

    public function preview(string $org,string $conn,string $product,array $collections=[],?string $publication=null,?string $resync=null):array
    {
        $shop=$this->observations->connection($org,$conn);
        if($shop['mode']!=='observation_only')throw new DomainException('Explicit COS catalog ownership required.');
        $native=$this->products->getProduct($org,$product);
        if($native===null)throw new DomainException('Canonical Product not found.');
        $token=$this->credentials->accessToken($shop['shop_domain'],$shop['secret_ref'],$org,$conn);
        $access=$this->access->inspect($shop['shop_domain'],$token);
        if(!$access['read_products']||!$access['write_products'])throw new DomainException('Shopify product scopes missing.');
        $prices=[];
        foreach($native['variants'] as $variant){
            $vid=$variant['variant_id'];
            $prices[$vid]=$this->prices->get($org,$vid,$access['currency']);
        }
        if($publication!==null){
            if(!$access['write_publications']||!preg_match('#^gid://shopify/Publication/[0-9]+$#D',$publication)
                ||($native['status']??null)!=='active'||($native['media']??[])===[])
                throw new DomainException('Publication requires an active canonical Product, verified media and granted publication scope.');
            foreach($native['variants'] as $variant){
                if(str_starts_with($variant['sku'],'SERIES01-'))throw new DomainException('SERIES01 concept products cannot be published.');
            }
            $pub=$this->reader->query($shop['shop_domain'],$token,'query COSPublication($id: ID!) { node(id:$id) { ... on Publication { id } } }',['id'=>$publication])['data']['node']??null;
            if(($pub['id']??null)!==$publication)throw new DomainException('Publication not verified for this shop.');
        }
        $plan=ShopifyOutboundPlanFactory::catalogDraft($org,$conn,$native,$prices,$access['currency'],$publication!==null);
        $plan['publication']=$publication;
        if($resync!==null&&(!preg_match('/^[a-f0-9]{32}$/D',$resync)||$this->nativeMapping($org,$conn,'product',$product)===null))
            throw new DomainException('Explicit resync identity on an already mapped Product required.');
        $plan['resync_id']=$resync;
        if(count($collections)>20||!array_is_list($collections)||count(array_unique($collections))!==count($collections))
            throw new DomainException('Bounded distinct collection IDs required.');
        sort($collections,SORT_STRING);
        foreach($collections as $collection){
            if(!is_string($collection)||!preg_match('#^gid://shopify/Collection/[0-9]+$#D',$collection))throw new DomainException('Invalid collection GID.');
            $row=$this->reader->query($shop['shop_domain'],$token,'query COSCollection($id: ID!) { collection(id:$id) { id } }',['id'=>$collection])['data']['collection']??null;
            if(($row['id']??null)!==$collection)throw new DomainException('Collection not verified in the connected shop.');
        }
        if($collections!==[])$plan['variables']['input']['collections']=$collections;
        $plan['collections']=$collections;
        $nativeMedia=$native['media']??[];
        if($nativeMedia!==[]&&(!$access['write_files']||$this->media===null))throw new DomainException('Verified media requires granted write_files and configured image source.');
        $plan['media']=$nativeMedia===[]?[]:$this->media->preview($org,$nativeMedia);
        return $plan;
    }

    public function assertAvailable(string $org,string $conn,string $product,string $digest):void
    {
        $stmt=$this->connection->prepare("SELECT 1 FROM pm_shopify_outbound_receipts WHERE organization_id=? AND connection_id=? AND product_id=? AND (state<>'verified' OR payload_hash=?) LIMIT 1");
        $stmt->execute([$org,$conn,$product,$digest]);
        if($stmt->fetchColumn()!==false)throw new DomainException('Pending, uncertain or identical prior provider claim; reconcile rather than replay.');
    }

    public function apply(string $org,string $conn,string $product,string $digest,string $key,array $collections=[],?string $publication=null,?string $resync=null):array
    {
        if(getenv('COS_SHOPIFY_WRITEBACK_ENABLED')!=='1')throw new DomainException('Shopify writeback rollout disabled.');
        if(!preg_match('/^wf:[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new DomainException('Pinned canonical Action required.');
        if($this->connection->inTransaction())throw new DomainException('Provider claim must commit before external mutation.');
        $lock='pm-out:'.substr(hash('sha256',json_encode([$org,$conn,$product],JSON_THROW_ON_ERROR)),0,48);
        $stmt=$this->connection->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$lock]);
        if((int)$stmt->fetchColumn()!==1)throw new DomainException('Concurrent Shopify product effect.');
        try{return $this->applyLocked($org,$conn,$product,$digest,$key,$collections,$publication,$resync);}
        finally{$stmt=$this->connection->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$lock]);}
    }

    private function applyLocked(string $org,string $conn,string $product,string $digest,string $key,array $collections=[],?string $publication=null,?string $resync=null):array
    {
        $plan=$this->preview($org,$conn,$product,$collections,$publication,$resync);
        $collections=$plan['collections'];
        if($publication!==null&&getenv('COS_SHOPIFY_PUBLICATION_ENABLED')!=='1')throw new DomainException('Explicit publication rollout disabled.');
        if(!hash_equals($digest,self::digest($plan)))throw new DomainException('Approved native payload changed.');
        $this->assertAvailable($org,$conn,$product,$digest);
        $shop=$this->observations->connection($org,$conn);
        $host=$shop['shop_domain'];$token=$this->credentials->accessToken($host,$shop['secret_ref'],$org,$conn);
        $access=$this->access->inspect($host,$token);
        if($access['currency']!==$plan['canonical_currency']||!$access['write_products']||!$access['read_products']||$shop['mode']!=='observation_only'||($publication!==null&&!$access['write_publications']))
            throw new DomainException('Live provider scope or ownership changed.');
        $variables=$plan['variables'];
        $handle=$variables['input']['handle'];
        $gid=$this->nativeMapping($org,$conn,'product',$product);
        $existing=$this->reader->query($host,$token,self::LOOKUP,['identifier'=>['handle'=>$handle]]);
        if(!array_key_exists('product',$existing['data']))throw new DomainException('Remote handle not verified.');
        $remote=$existing['data']['product'];
        if($publication!==null&&$gid===null)throw new DomainException('Publication requires a previously verified mapped draft.');
        if($gid===null&&$remote!==null)throw new DomainException('Unmapped remote Product; never overwrite.');
        $remoteVariants=[];
        if($gid!==null){
            if(!is_array($remote)||($remote['id']??null)!==$gid||!in_array($remote['status']??null,$publication===null?['DRAFT']:['DRAFT','ACTIVE'],true)
                ||($remote['variants']['pageInfo']['hasNextPage']??null)!==false||!is_array($remote['variants']['nodes']??null))
                throw new DomainException('Mapped draft identity or complete variant readback missing.');
            foreach($remote['variants']['nodes'] as $row){
                if(!is_string($row['id']??null)||isset($remoteVariants[$row['id']]))throw new DomainException('Invalid existing variant.');
                $remoteVariants[$row['id']]=$row;
            }
            $variables['identifier']=['id'=>$gid];
        }
        $i=0;$retained=[];
        foreach($plan['native_variants'] as $vid=>$row){
            $mapped=$this->nativeMapping($org,$conn,'variant',$vid);
            if($mapped!==null){
                if(!isset($remoteVariants[$mapped]))throw new DomainException('Mapped variant is absent from the remote product.');
                $variables['input']['variants'][$i]['id']=$mapped;
                $retained[$mapped]=true;
            }
            // Quoted, escaped exact search; fail closed on any conflicting result.
            $sku=$row['sku'];$query='sku:"'.$sku.'"';
            $matches=$this->reader->query($host,$token,self::SKU_LOOKUP,['query'=>$query])['data']['productVariants']??null;
            if(!is_array($matches)||!is_array($matches['nodes']??null)||($matches['pageInfo']['hasNextPage']??null)!==false)
                throw new DomainException('SKU availability not verified.');
            foreach($matches['nodes'] as $match){
                if(($match['id']??null)!==$mapped)throw new DomainException('Remote SKU conflict.');
            }
            ++$i;
        }
        // productSet deletes omitted list members. Never delete remote variants
        // or absorb unmanaged ones in a routine writeback.
        if(array_diff_key($remoteVariants,$retained)!==[])throw new DomainException('Remote variants would be deleted; reconcile explicitly.');
        if($publication!==null){
            $scope=$this->reader->query($host,$token,'query COSPublicationFence($id: ID!) { product(id:$id) { id resourcePublicationsV2(first:50,onlyPublished:false) { nodes { isPublished publication { id } } pageInfo { hasNextPage } } } }',['id'=>$gid])['data']['product']??null;
            if(($scope['id']??null)!==$gid||($scope['resourcePublicationsV2']['pageInfo']['hasNextPage']??null)!==false
                ||!is_array($scope['resourcePublicationsV2']['nodes']??null))throw new DomainException('Complete publication ownership evidence required.');
            foreach($scope['resourcePublicationsV2']['nodes'] as $row){
                if(!is_bool($row['isPublished']??null)||!is_string($row['publication']['id']??null)
                    ||($row['isPublished']&&$row['publication']['id']!==$publication))
                    throw new DomainException('Existing publication outside approved destination.');
            }
        }
        $fileGids=[];$existingChannels=null;
        if($gid!==null&&($plan['media']!==[]||$collections!==[])){
            $existingChannels=$this->channel($host,$token,$gid);
            if($collections!==[]&&array_diff($existingChannels['collections'],$collections)!==[])
                throw new DomainException('Routine collection sync would remove existing membership; explicit removal review required.');
        }
        if($plan['media']!==[]){
            if(!$access['write_files']||$this->media===null)throw new DomainException('Media write scope changed.');
            foreach($plan['media'] as $mid=>$image){
                $mapped=$this->nativeMapping($org,$conn,'media',$mid);
                if($mapped!==null){$this->media->ready($host,$token,$mapped);$fileGids[$mid]=$mapped;}
            }
            if($existingChannels!==null&&array_diff($existingChannels['media'],array_values($fileGids))!==[])
                throw new DomainException('Existing unmanaged media would be detached; reconcile explicitly.');
        }
        if(!hash_equals($digest,self::digest($this->preview($org,$conn,$product,$collections,$publication,$resync))))throw new DomainException('Native state changed before provider claim.');
        $stmt=$this->connection->prepare("INSERT INTO pm_shopify_outbound_receipts (organization_id,connection_id,product_id,action_key,payload_hash,state,request_json) VALUES (?,?,?,?,?,'claimed',?)");
        $stmt->execute([$org,$conn,$product,$key,$digest,json_encode($plan,JSON_THROW_ON_ERROR)]);
        try{
            if($plan['media']!==[]){
                foreach($plan['media'] as $mid=>$image){
                    if(!isset($fileGids[$mid]))$fileGids[$mid]=$this->media->create($org,$host,$token,$mid,$image);
                }
                $variables['input']['files']=array_map(static fn($gid)=>['id'=>$gid],array_values($fileGids));
                $i=0;foreach($plan['native_variants'] as $vid=>$row){
                    foreach($plan['media'] as $mid=>$image){
                        if($image['variant_id']===$vid){$variables['input']['variants'][$i]['file']=['id'=>$fileGids[$mid]];break;}
                    }
                    ++$i;
                }
            }
            $response=$this->http->post('https://'.$host.'/admin/api/'.ShopifyReadClient::API_VERSION.'/graphql.json',
                ['Content-Type'=>'application/json','X-Shopify-Access-Token'=>$token],
                json_encode(['query'=>$plan['query'],'variables'=>$variables],JSON_THROW_ON_ERROR));
            if($response['status']!==200)throw new DomainException('Provider result uncertain.');
            $payload=json_decode($response['body'],true,flags:JSON_THROW_ON_ERROR);
            $effect=$payload['data']['productSet']??null;$effectGid=$effect['product']['id']??null;
            if(!empty($payload['errors'])||!is_array($effect)||($effect['userErrors']??null)!==[]||!is_string($effectGid)
                ||($gid!==null&&$effectGid!==$gid))throw new DomainException('Provider result rejected or uncertain.');
            $fresh=$this->reader->query($host,$token,self::BY_ID,['id'=>$effectGid])['data']['product']??null;
            $verified=ShopifyOutboundPlanFactory::verifyCatalogReadback($fresh??[],$plan);
            if($verified['product_gid']!==$effectGid)throw new DomainException('Provider identity mismatch.');
            foreach($verified['variants'] as $vid=>$vgid){
                $mapped=$this->nativeMapping($org,$conn,'variant',$vid);
                if($mapped!==null&&$mapped!==$vgid)throw new DomainException('Variant identity changed.');
            }
            if($fileGids!==[]||$collections!==[]){
                $channel=$this->channel($host,$token,$effectGid);
                if(($fileGids!==[]&&!self::sameIds(array_values($fileGids),$channel['media']))
                    ||($collections!==[]&&!self::sameIds($collections,$channel['collections'])))
                    throw new DomainException('Independent channel readback differs from approved list.');
            }
            if(!hash_equals($digest,self::digest($this->preview($org,$conn,$product,$collections,$publication,$resync))))throw new DomainException('Native state changed during external effect.');
            if($publication!==null){
                $publish=ShopifyOutboundPlanFactory::publicationRequest($org,$conn,$effectGid,$publication);
                // A previous publication can be reconciled, never duplicated.
                if(!$this->published($host,$token,$effectGid,$publication)){
                    $reply=$this->http->post('https://'.$host.'/admin/api/'.ShopifyReadClient::API_VERSION.'/graphql.json',
                        ['Content-Type'=>'application/json','X-Shopify-Access-Token'=>$token],
                        json_encode(['query'=>$publish['query'],'variables'=>$publish['variables']],JSON_THROW_ON_ERROR));
                    $data=json_decode($reply['body'],true,flags:JSON_THROW_ON_ERROR);
                    if($reply['status']!==200||!empty($data['errors'])||($data['data']['publishablePublish']['userErrors']??null)!==[])
                        throw new DomainException('Publication result uncertain.');
                }
                if(!$this->published($host,$token,$effectGid,$publication))throw new DomainException('Independent publication readback missing.');
            }

            $result=['product_id'=>$product,'connection_id'=>$conn,'product_gid'=>$effectGid,
                'variant_id'=>array_key_first($verified['variants']),'variant_gid'=>reset($verified['variants']),
                'variants'=>$verified['variants'],'collections'=>$collections,'payload_hash'=>$digest,'state'=>'verified'];
            if($fileGids!==[])$result['media']=$fileGids;
            if($publication!==null)$result['publication_gid']=$publication;
            $this->connection->beginTransaction();
            $this->observations->map($org,$conn,'product',$effectGid,$product);
            foreach($verified['variants'] as $vid=>$vgid)$this->observations->map($org,$conn,'variant',$vgid,$vid);
            foreach($fileGids as $mid=>$mgid)$this->observations->map($org,$conn,'media',$mgid,$mid);
            $stmt=$this->connection->prepare("UPDATE pm_shopify_outbound_receipts SET state='verified',product_gid=?,variant_gid=?,result_json=? WHERE organization_id=? AND connection_id=? AND product_id=? AND action_key=? AND state='claimed'");
            $stmt->execute([$effectGid,$result['variant_gid'],json_encode($result,JSON_THROW_ON_ERROR),$org,$conn,$product,$key]);
            if($stmt->rowCount()!==1)throw new DomainException('Lost outbound claim.');
            $this->connection->commit();
            return $result;
        }catch(Throwable $e){
            if($this->connection->inTransaction())$this->connection->rollBack();
            $stmt=$this->connection->prepare("UPDATE pm_shopify_outbound_receipts SET state='uncertain' WHERE organization_id=? AND connection_id=? AND product_id=? AND action_key=? AND state='claimed'");
            $stmt->execute([$org,$conn,$product,$key]);
            throw new DomainException('Shopify outbound effect uncertain; reconcile read-only, never replay automatically.',0,$e);
        }
    }

    public function audit(string $org,string $conn,string $product,?string $key=null):array
    {
        $shop=$this->observations->connection($org,$conn);
        $sql='SELECT action_key,state,product_gid,variant_gid,payload_hash,result_json,request_json,updated_at FROM pm_shopify_outbound_receipts WHERE organization_id=? AND connection_id=? AND product_id=?';
        $params=[$org,$conn,$product];if($key!==null){$sql.=' AND action_key=?';$params[]=$key;}
        $stmt=$this->connection->prepare($sql.' ORDER BY created_at DESC,action_key DESC LIMIT 1');
        $stmt->execute($params);$receipt=$stmt->fetch(PDO::FETCH_ASSOC);
        if($receipt===false)return ['state'=>'not_claimed','product_id'=>$product,'automatic_replay_authorized'=>false];
        $saved=json_decode($receipt['result_json']??'null',true,flags:JSON_THROW_ON_ERROR);
        $request=json_decode($receipt['request_json']??'null',true,flags:JSON_THROW_ON_ERROR);
        $collections=$request['collections']??$saved['collections']??[];
        $publication=$request['publication']??$saved['publication_gid']??null;
        $resync=$request['resync_id']??null;
        $plan=$this->preview($org,$conn,$product,$collections,$publication,$resync);
        $token=$this->credentials->accessToken($shop['shop_domain'],$shop['secret_ref'],$org,$conn);
        $remote=$this->reader->query($shop['shop_domain'],$token,self::LOOKUP,['identifier'=>['handle'=>$plan['variables']['input']['handle']]])['data']['product']??null;
        $matches=false;$mapped=false;
        try{
            $verified=ShopifyOutboundPlanFactory::verifyCatalogReadback($remote??[],$plan);
            $saved=json_decode($receipt['result_json']??'null',true,flags:JSON_THROW_ON_ERROR);
            $matches=$verified['product_gid']===$receipt['product_gid']&&$verified['variants']==($saved['variants']??null);
            $mapped=$this->nativeMapping($org,$conn,'product',$product)===$verified['product_gid'];
            foreach($verified['variants'] as $vid=>$vgid)$mapped=$mapped&&$this->nativeMapping($org,$conn,'variant',$vid)===$vgid;
            $media=$saved['media']??[];
            foreach($media as $mid=>$mgid)$mapped=$mapped&&$this->nativeMapping($org,$conn,'media',$mid)===$mgid;
            if($publication!==null)$matches=$matches&&$this->published($shop['shop_domain'],$token,$verified['product_gid'],$publication);
            if($media!==[]||$collections!==[]){
                $channel=$this->channel($shop['shop_domain'],$token,$verified['product_gid']);
                $matches=$matches&&($media===[]||self::sameIds(array_values($media),$channel['media']))
                    &&($collections===[]||self::sameIds($collections,$channel['collections']));
            }
        }catch(DomainException){}
        $pinned=hash_equals($receipt['payload_hash'],self::digest($plan));
        return ['state'=>$receipt['state'],'product_id'=>$product,'connection_id'=>$conn,'action_key'=>$receipt['action_key'],
            'remote_present'=>is_array($remote),'remote_matches_receipt'=>$matches,'canonical_mapping_matches'=>$mapped,
            'current_payload_matches'=>$pinned,'independent_verified'=>$receipt['state']==='verified'&&$matches&&$mapped&&$pinned,
            'automatic_replay_authorized'=>false];
    }

    private function published(string $host,string $token,string $product,string $publication):bool
    {
        $row=$this->reader->query($host,$token,'query COSPublicationReadback($id: ID!, $publication: ID!) { product(id:$id) { id status publishedOnPublication(publicationId:$publication) } }',
            ['id'=>$product,'publication'=>$publication])['data']['product']??null;
        if(($row['id']??null)!==$product||($row['status']??null)!=='ACTIVE'||!is_bool($row['publishedOnPublication']??null))
            throw new DomainException('Publication state not independently observed.');
        return $row['publishedOnPublication'];
    }

    private function channel(string $host,string $token,string $gid):array
    {
        $p=$this->reader->query($host,$token,'query COSChannelProduct($id: ID!) { product(id:$id) { id media(first:50) { nodes { id } pageInfo { hasNextPage } } collections(first:50) { nodes { id } pageInfo { hasNextPage } } } }',['id'=>$gid])['data']['product']??null;
        if(!is_array($p)||($p['id']??null)!==$gid)throw new DomainException('Channel identity not verified.');
        $result=[];
        foreach(['media','collections'] as $field){
            $nodes=$p[$field]['nodes']??null;
            if(!is_array($nodes)||($p[$field]['pageInfo']['hasNextPage']??null)!==false)
                throw new DomainException('Incomplete channel membership.');
            $ids=array_column($nodes,'id');
            if(count($ids)!==count($nodes)||count(array_unique($ids))!==count($ids))throw new DomainException('Malformed channel membership.');
            $result[$field]=$ids;
        }
        return $result;
    }
    private static function sameIds(array $a,array $b):bool
    {sort($a,SORT_STRING);sort($b,SORT_STRING);return $a===$b;}

    private function nativeMapping(string $org,string $conn,string $type,string $id):?string
    {
        $stmt=$this->connection->prepare('SELECT external_gid FROM pm_external_mappings WHERE organization_id=? AND connection_id=? AND entity_type=? AND canonical_id=?');
        $stmt->execute([$org,$conn,$type,$id]);$value=$stmt->fetchColumn();
        return $value===false?null:(string)$value;
    }

    public static function digest(array $plan):string
    {
        return hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR));
    }
}
