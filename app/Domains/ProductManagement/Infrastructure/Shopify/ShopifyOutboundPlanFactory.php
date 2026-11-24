<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Domains\ProductManagement\Domain\ProductRules;

/**
 * S3/S4 Shopify GraphQL *request compiler*, not an executor.
 *
 * Callers must read tenant-owned canonical Product/Price, verify signed
 * approved Workflow Action and lock idempotent outbound receipts BEFORE
 * passing any output to a provider-write transport. This class neither
 * authorizes nor performs external side effects and is not registered as
 * a Capability adapter. Shopify never becomes the canonical Product owner.
 */
final class ShopifyOutboundPlanFactory
{
    private const PRODUCT_SET = <<<'GRAPHQL'
mutation COSProductSet($identifier: ProductSetIdentifiers, $input: ProductSetInput!) {
  productSet(identifier: $identifier, input: $input, synchronous: true) {
    product {
      id
      handle
      status
      variants(first: 5) { nodes { id sku price } }
    }
    userErrors { field message code }
  }
}
GRAPHQL;

    private const PRODUCT_READBACK = <<<'GRAPHQL'
query COSProductReadback($id: ID!) {
  product(id: $id) {
    id handle status title
    variants(first: 5) { nodes { id sku price } }
  }
}
GRAPHQL;

    private const PUBLISH = <<<'GRAPHQL'
mutation COSPublishProduct($id: ID!, $input: [PublicationInput!]!) {
  publishablePublish(id: $id, input: $input) {
    publishable { availablePublicationsCount { count } }
    userErrors { field message }
  }
}
GRAPHQL;

    /**
     * Prepare a stable idempotent handle-scoped DRAFT upsert.
     * Single-variant pilot only. No files, stock, publish, or checkout.
     *
     * @param array<string,mixed> $canonical
     * @return array<string,mixed>
     */
    public static function draftProduct(string $org,string $connectionId,array $canonical,string $priceUsd):array
    {
        self::scope($org,$connectionId);
        $productId=$canonical['product_id']??null;
        if(!is_string($productId)||!preg_match('/^[a-f0-9]{32}$/D',$productId)) {
            throw new DomainException('Canonical Product ID required before Shopify staging.');
        }
        $name=ProductRules::name((string)($canonical['name']??''));
        $variants=$canonical['variants']??null;
        if(!is_array($variants)||count($variants)!==1||!is_array($variants[0])) {
            throw new DomainException('Shopify SERIES01 S3 pilot requires exactly one canonical variant.');
        }
        $sku=ProductRules::sku((string)($variants[0]['sku']??''));
        if(!str_starts_with($sku,'SERIES01-')||!preg_match('/^SERIES01-[LFA]0[1-3]$/D',$sku)) {
            throw new DomainException('Only pinned SERIES01 SKUs are eligible for initial writeback draft.');
        }
        if(!preg_match('/^[3-9][0-9]{2}(?:\.[0-9]{1,2})?$|^[1-9][0-9]{3,}(?:\.[0-9]{1,2})?$/D',$priceUsd)) {
            throw new DomainException('Verified price must meet SERIES01 $300 floor.');
        }
        // Handle includes tenant+connection scope; never use customer-provided handle.
        $handle='cos-'.substr(hash('sha256',$org.':'.$connectionId),0,12).'-'.$productId;
        $description=trim((string)($canonical['description']??''));
        if(mb_strlen($description)>10000) throw new DomainException('Product description exceeds bounded preview.');
        $html=htmlspecialchars($description,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $input=[
            'title'=>$name,
            'handle'=>$handle,
            'descriptionHtml'=>'<p>'.$html.'</p>',
            'status'=>'DRAFT',
            'productType'=>'SERIES01',
            'tags'=>['cos-managed','series01','concept-not-for-sale'],
            'productOptions'=>[
                ['name'=>'Finish','position'=>1,'values'=>[['name'=>'Standard']]],
            ],
            'variants'=>[
                [
                    'optionValues'=>[['optionName'=>'Finish','name'=>'Standard']],
                    'sku'=>$sku,
                    'price'=>$priceUsd,
                ],
            ],
        ];
        $variables=['identifier'=>['handle'=>$handle],'input'=>$input];
        $payloadHash=hash('sha256',json_encode($variables,JSON_THROW_ON_ERROR));
        return [
            'kind'=>'product_set_draft','shop_domain_scope'=>'connection_id_only',
            'organization_id'=>$org,'connection_id'=>$connectionId,'product_id'=>$productId,
            'sku'=>$sku,'payload_hash'=>$payloadHash,
            'query'=>self::PRODUCT_SET,'variables'=>$variables,
            'readback_query'=>self::PRODUCT_READBACK,
            'requires_approved_action'=>true,'publishable'=>false,
        ];
    }

    /**
     * Shapes an explicit publication request but does not authorize execution.
     * A future Action Handler must independently validate approved Product
     * readiness, compliant media, Shopify GID, publication ownership, and
     * immutable Workflow step receipt. Never execute for concept-only products.
     */
    public static function publicationRequest(
        string $org,string $connectionId,string $productGid,string $publicationGid
    ):array {
        self::scope($org,$connectionId);
        if(!preg_match('#^gid://shopify/Product/[0-9]+$#D',$productGid)
            ||!preg_match('#^gid://shopify/Publication/[0-9]+$#D',$publicationGid)) {
            throw new DomainException('Cannot prepare publication without validated Shopify GIDs.');
        }
        $variables=['id'=>$productGid,'input'=>[['publicationId'=>$publicationGid]]];
        return [
            'kind'=>'publishable_publish','organization_id'=>$org,'connection_id'=>$connectionId,
            'query'=>self::PUBLISH,'variables'=>$variables,
            'payload_hash'=>hash('sha256',json_encode($variables,JSON_THROW_ON_ERROR)),
            'requires_approved_action'=>true,
            'blocked_until'=>['native_product_ready','compliance','media_verified','independent_approval','shopify_publication_readback'],
        ];
    }

    /** @param array<string,mixed> $result */
    public static function verifyDraftReadback(array $result,string $expectedHandle,string $expectedSku,string $expectedPriceUsd):string
    {
        if(!empty($result['errors'])||!is_array($result['data']['product']??null)) {
            throw new DomainException('Shopify writeback readback missing or GraphQL rejected.');
        }
        $product=$result['data']['product'];
        $gid=$product['id']??null;
        if(!is_string($gid)||!preg_match('#^gid://shopify/Product/[0-9]+$#D',$gid)
            ||($product['handle']??null)!==$expectedHandle
            ||($product['status']??null)!=='DRAFT'
            ||!is_array($product['variants']['nodes']??null)
            ||count($product['variants']['nodes'])!==1
            ||($product['variants']['nodes'][0]['sku']??null)!==$expectedSku
            ||(string)($product['variants']['nodes'][0]['price']??'')!==$expectedPriceUsd) {
            throw new DomainException('Shopify returned nonmatching product; quarantine instead of remapping.');
        }
        return $gid;
    }

    private static function scope(string $org,string $conn):void
    {
        if($org===''||strlen($org)>40||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/D',$org)
            ||!preg_match('/^[a-f0-9]{32}$/D',$conn)) {
            throw new DomainException('Outbound Shopify request requires valid tenant and connection IDs.');
        }
    }
}
