<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

/** Read-only S4 reconciliation. Never promotes Shopify observations into native state. */
final readonly class ShopifyChannelReadback
{
    private const PRODUCT='query COSChannelProduct($id: ID!) { product(id: $id) { id status title media(first: 50) { nodes { id mediaContentType status } pageInfo { hasNextPage } } collections(first: 50) { nodes { id title } pageInfo { hasNextPage } } resourcePublicationsV2(first: 50, onlyPublished: false) { nodes { isPublished publication { id } } pageInfo { hasNextPage } } } }';
    public function __construct(private ShopifyObservationStore $observations,
        private ShopifyClientCredentials $credentials,private ShopifyReadClient $reader,
        private ShopifyAccessReadiness $access) {}
    public function inspect(string $org,string $conn,string $gid):array
    {
        if(!preg_match('#^gid://shopify/Product/[0-9]+$#D',$gid))throw new DomainException('Shopify Product GID required.');
        $c=$this->observations->connection($org,$conn);
        $token=$this->credentials->accessToken($c['shop_domain'],$c['secret_ref'],$org,$conn);
        $access=$this->access->inspect($c['shop_domain'],$token);
        if(!$access['read_products'])throw new DomainException('Product read scope required.');
        $p=$this->reader->query($c['shop_domain'],$token,self::PRODUCT,['id'=>$gid])['data']['product']??null;
        if(!is_array($p)||($p['id']??null)!==$gid)throw new DomainException('Provider identity unavailable.');
        $complete=true;$counts=[];
        foreach(['media','collections','resourcePublicationsV2'] as $field){
            $part=$p[$field]??null;
            if(!is_array($part)||!is_array($part['nodes']??null)||!is_bool($part['pageInfo']['hasNextPage']??null))
                throw new DomainException('Incomplete channel observation.');
            $complete=$complete&&!$part['pageInfo']['hasNextPage'];
            $counts[$field]=count($part['nodes']);
        }
        return ['connection_id'=>$conn,'product_gid'=>$gid,'status'=>$p['status'],
            'canonical_product_id'=>$this->observations->mapping($org,$conn,'product',$gid)['canonical_id']??null,
            'complete'=>$complete,'counts'=>$counts,'media'=>$p['media']['nodes'],'collections'=>$p['collections']['nodes'],
            'publications'=>$p['resourcePublicationsV2']['nodes'],'scope_evidence'=>$access,
            'provider_write_authorized'=>false];
    }
}
