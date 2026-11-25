<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\PricingService;
use PDO;
use Throwable;

/** Bounded SERIES01 pilot. External effects only via independently approved Action.
 * One mutation claim per canonical Product/connection; ambiguous results are fenced.
 * No update of existing remote products, stock, publication or automatic replay.
 */
final readonly class ShopifyDraftWritebackService
{
    private const LOOKUP='query COSDraftLookup($identifier: ProductIdentifierInput!) { product: productByIdentifier(identifier: $identifier) { id handle status title variants(first: 5) { nodes { id sku price } pageInfo { hasNextPage } } } }';
    private const SKU_LOOKUP='query COSSkuLookup($query: String!) { productVariants(first: 5, query: $query) { nodes { id sku } pageInfo { hasNextPage } } }';
    public function __construct(private PDO $connection,private ShopifyObservationStore $observations,
        private ShopifyClientCredentials $credentials,private ShopifyReadClient $reader,
        private ShopifyAccessReadiness $access,private ShopifyHttpTransportInterface $http,
        private ProductManagementService $products,private PricingService $prices) {}

    public function preview(string $org,string $conn,string $product):array
    {
        $shop=$this->observations->connection($org,$conn);
        if($shop['mode']!=='observation_only')throw new DomainException('Explicit COS catalog ownership required: observation-only connection.');
        $native=$this->products->getProduct($org,$product);
        if($native===null||$native['status']!=='draft'||count($native['variants'])!==1||$native['variants'][0]['status']!=='active')
            throw new DomainException('One active canonical variant on a draft Product required.');
        $variant=$native['variants'][0]['variant_id'];
        $base=$this->prices->get($org,$variant,'USD');
        if(!is_string($base['price'])||!preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/D',$base['price']))
            throw new DomainException('Canonical USD base price required.');
        $parts=explode('.',$base['price'],2);$frac=$parts[1]??'';
        if(trim(substr($frac,2),'0')!=='')throw new DomainException('USD price must be exact cents.');
        $amount=$parts[0].'.'.str_pad(substr($frac,0,2),2,'0');
        $token=$this->credentials->accessToken($shop['shop_domain'],$shop['secret_ref'],$org,$conn);
        $access=$this->access->inspect($shop['shop_domain'],$token);
        if(!$access['read_products']||!$access['write_products'])throw new DomainException('Shopify product scopes missing.');
        $plan=ShopifyOutboundPlanFactory::draftProduct($org,$conn,$native,$amount,$access['currency']);
        // Create-only mutation: an unknown remote object must never be overwritten by handle upsert.
        unset($plan['variables']['identifier']);
        $plan['payload_hash']=hash('sha256',json_encode($plan['variables'],JSON_THROW_ON_ERROR));
        return $plan+['native_variant_id'=>$variant,'native_revision'=>(int)$native['revision'],'price_revision'=>$base['revision']];
    }

    public function apply(string $org,string $conn,string $product,string $digest,string $key):array
    {
        if(getenv('COS_SHOPIFY_WRITEBACK_ENABLED')!=='1')throw new DomainException('Shopify writeback rollout disabled.');
        if(!preg_match('/^wf:[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new DomainException('Pinned canonical Action required.');
        if($this->connection->inTransaction())throw new DomainException('Provider claim must commit before external mutation.');
        $plan=$this->preview($org,$conn,$product);
        if(!hash_equals($digest,self::digest($plan)))throw new DomainException('Approved native payload changed.');
        $shop=$this->observations->connection($org,$conn);
        $host=$shop['shop_domain'];$token=$this->credentials->accessToken($host,$shop['secret_ref'],$org,$conn);
        $access=$this->access->inspect($host,$token);
        if($access['currency']!=='USD'||!$access['write_products']||!$access['read_products']||$shop['mode']!=='observation_only')
            throw new DomainException('Live provider scope or ownership changed.');
        $handle=$plan['variables']['input']['handle'];$sku=$plan['sku'];
        $existing=$this->reader->query($host,$token,self::LOOKUP,['identifier'=>['handle'=>$handle]]);
        if(!array_key_exists('product',$existing['data'])||$existing['data']['product']!==null)throw new DomainException('Remote handle already exists or not verified absent.');
        $matches=$this->reader->query($host,$token,self::SKU_LOOKUP,['query'=>'sku:'.$sku])['data']['productVariants']??null;
        if(!is_array($matches)||($matches['nodes']??null)!==[]||($matches['pageInfo']['hasNextPage']??null)!==false)
            throw new DomainException('Remote SKU already exists or not verified absent.');
        $stmt=$this->connection->prepare('SELECT external_gid FROM pm_external_mappings WHERE organization_id=? AND connection_id=? AND ((entity_type=\'product\' AND canonical_id=?) OR (entity_type=\'variant\' AND canonical_id=?)) LIMIT 1');
        $stmt->execute([$org,$conn,$product,$plan['native_variant_id']]);
        if($stmt->fetchColumn()!==false)throw new DomainException('Existing native mapping requires reconciliation.');
        // Autocommit INSERT is the durable one-shot claim. Unique collision denies both same and new Action keys.
        $stmt=$this->connection->prepare("INSERT INTO pm_shopify_outbound_receipts (organization_id,connection_id,product_id,action_key,payload_hash,state) VALUES (?,?,?,?,?,'claimed')");
        $stmt->execute([$org,$conn,$product,$key,$digest]);
        try{
            $response=$this->http->post('https://'.$host.'/admin/api/'.ShopifyReadClient::API_VERSION.'/graphql.json',
                ['Content-Type'=>'application/json','X-Shopify-Access-Token'=>$token],
                json_encode(['query'=>$plan['query'],'variables'=>$plan['variables']],JSON_THROW_ON_ERROR));
            if($response['status']!==200)throw new DomainException('Provider result uncertain.');
            $payload=json_decode($response['body'],true,flags:JSON_THROW_ON_ERROR);
            $effect=$payload['data']['productSet']??null;
            if(!empty($payload['errors'])||!is_array($effect)||($effect['userErrors']??null)!==[]||!is_string($effect['product']['id']??null))
                throw new DomainException('Provider result rejected or uncertain.');
            $fresh=$this->reader->query($host,$token,self::LOOKUP,['identifier'=>['handle'=>$handle]]);
            $amount=$plan['variables']['input']['variants'][0]['price'];
            $gid=ShopifyOutboundPlanFactory::verifyDraftReadback($fresh,$handle,$sku,$amount);
            $remote=$fresh['data']['product'];
            $variant=$remote['variants']['nodes'][0]['id']??null;
            if($gid!==$effect['product']['id']||($remote['title']??null)!==$plan['variables']['input']['title']
                ||($remote['variants']['pageInfo']['hasNextPage']??null)!==false
                ||!is_string($variant)||!preg_match('#^gid://shopify/ProductVariant/[0-9]+$#D',$variant))
                throw new DomainException('Independent provider readback mismatch.');
            // Recheck pinned canonical state before attaching identity.
            if(!hash_equals($digest,self::digest($this->preview($org,$conn,$product))))throw new DomainException('Native state changed during external effect.');
            $result=['product_id'=>$product,'variant_id'=>$plan['native_variant_id'],'connection_id'=>$conn,
                'product_gid'=>$gid,'variant_gid'=>$variant,'payload_hash'=>$digest,'state'=>'verified'];
            $this->connection->beginTransaction();
            $this->observations->map($org,$conn,'product',$gid,$product);
            $this->observations->map($org,$conn,'variant',$variant,$plan['native_variant_id']);
            $stmt=$this->connection->prepare("UPDATE pm_shopify_outbound_receipts SET state='verified',product_gid=?,variant_gid=?,result_json=? WHERE organization_id=? AND connection_id=? AND product_id=? AND action_key=? AND state='claimed'");
            $stmt->execute([$gid,$variant,json_encode($result,JSON_THROW_ON_ERROR),$org,$conn,$product,$key]);
            if($stmt->rowCount()!==1)throw new DomainException('Lost outbound claim.');
            $this->connection->commit();
            return $result;
        }catch(Throwable $e){
            if($this->connection->inTransaction())$this->connection->rollBack();
            $stmt=$this->connection->prepare("UPDATE pm_shopify_outbound_receipts SET state='uncertain' WHERE organization_id=? AND connection_id=? AND product_id=? AND action_key=? AND state='claimed'");
            $stmt->execute([$org,$conn,$product,$key]);
            throw new DomainException('Shopify outbound effect uncertain; reconcile read-only, never replay automatically.');
        }
    }

    public static function digest(array $plan):string
    {
        return hash('sha256',json_encode([$plan['payload_hash'],$plan['native_variant_id'],$plan['native_revision'],$plan['price_revision'],$plan['connection_id'],$plan['organization_id']],JSON_THROW_ON_ERROR));
    }
}
