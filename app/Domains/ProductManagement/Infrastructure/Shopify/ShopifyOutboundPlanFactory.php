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
      variants(first: 10) { nodes { id sku price } }
    }
    userErrors { field message code }
  }
}
GRAPHQL;

    private const PRODUCT_READBACK = <<<'GRAPHQL'
query COSProductReadback($id: ID!) {
  product(id: $id) {
    id handle status title
    variants(first: 10) { nodes { id sku price } }
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
    public static function draftProduct(string $org,string $connectionId,array $canonical,string $priceUsd,string $verifiedShopCurrency):array
    {
        self::scope($org,$connectionId);
        // Shopify MoneyInput interprets a bare amount in the shop's currency.
        // SERIES01 prices are USD business hypotheses. An actual UAH shop
        // must never publish/create a 390 UAH listing for a $390 quote.
        // Production FX conversion belongs to a separately approved pricing
        // contract with rate, timestamp and shop-currency evidence.
        if ($verifiedShopCurrency !== 'USD') {
            throw new DomainException('SERIES01 USD price cannot be written to a non-USD Shopify store.');
        }
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
            'canonical_currency'=>'USD','required_shop_currency'=>$verifiedShopCurrency,
            'query'=>self::PRODUCT_SET,'variables'=>$variables,
            'readback_query'=>self::PRODUCT_READBACK,
            'requires_approved_action'=>true,'publishable'=>false,
        ];
    }

    /**
     * Compile the complete native variant list. Shopify productSet treats list
     * fields as authoritative; never silently omit an archived/missing variant.
     * Inventory quantities and policies remain exclusively outside this contract.
     */
    public static function catalogDraft(string $org,string $conn,array $native,array $prices,string $currency,bool $publish=false):array
    {
        self::scope($org,$conn);
        $id=$native['product_id']??null;
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!in_array($currency,['USD','EUR','UAH'],true)
            ||($native['status']??null)!==($publish?'active':'draft'))throw new DomainException('Canonical draft and authenticated currency required.');
        $source=$native['variants']??[];
        if(!is_array($source)||count($source)<1||count($source)>10)throw new DomainException('Expected 1–10 canonical variants.');
        $variants=[];$identities=[];$options=[];$names=null;$tuples=[];$skus=[];
        $series=false;
        foreach($source as $row){
            $vid=$row['variant_id']??null;
            if(!is_string($vid)||!preg_match('/^[a-f0-9]{32}$/D',$vid)||($row['status']??null)!=='active')
                throw new DomainException('All variants must be active and identified.');
            $sku=ProductRules::sku((string)($row['sku']??''));
            if(isset($skus[strtolower($sku)]))throw new DomainException('Duplicate variant SKU.');
            $skus[strtolower($sku)]=true;
            $base=$prices[$vid]??null;
            if(!is_array($base)||($base['currency']??null)!==$currency||($base['revision']??0)<1
                ||!is_string($base['price']??null)||!preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/D',$base['price']))
                throw new DomainException('Exact canonical shop-currency base price required for every variant.');
            [$whole,$fraction]=array_pad(explode('.',$base['price'],2),2,'');
            if(trim(substr($fraction,2),'0')!=='')throw new DomainException('Unsupported sub-cent price.');
            $amount=$whole.'.'.str_pad(substr($fraction,0,2),2,'0');
            if(str_starts_with($sku,'SERIES01-')){
                $series=true;
                if($currency!=='USD'||!preg_match('/^SERIES01-[LFA]0[1-3]$/D',$sku)||(float)$amount<300)
                    throw new DomainException('SERIES01 requires USD and the pinned minimum price.');
            }
            $fields=isset($row['options_json'])?json_decode($row['options_json'],true,flags:JSON_THROW_ON_ERROR):[];
            if(!is_array($fields))throw new DomainException('Invalid canonical variant options.');
            if($fields===[])$fields=count($source)===1?['Finish'=>'Standard']:['SKU'=>$sku];
            ksort($fields,SORT_STRING);
            if(count($fields)>3||($names!==null&&$names!==array_keys($fields)))throw new DomainException('Shopify requires a consistent option set of 1–3 dimensions.');
            $names=array_keys($fields);
            $tuple=json_encode(array_values($fields),JSON_THROW_ON_ERROR);
            if(isset($tuples[$tuple]))throw new DomainException('Duplicate option combination.');
            $tuples[$tuple]=true;
            $values=[];
            foreach($fields as $name=>$value){
                if(!is_string($name)||!is_string($value)||trim($value)===''||mb_strlen($value)>190)
                    throw new DomainException('Invalid option name or value.');
                $options[$name][$value]=$value;
                $values[]=['optionName'=>$name,'name'=>$value];
            }
            $variants[]=['optionValues'=>$values,'sku'=>$sku,'price'=>$amount];
            $identities[$vid]=['sku'=>$sku,'price'=>$amount,'variant_revision'=>(int)$row['revision'],'price_revision'=>$base['revision']];
        }
        $productOptions=[];foreach($options as $name=>$values)$productOptions[]=['name'=>$name,'position'=>count($productOptions)+1,
            'values'=>array_map(static fn($value)=>['name'=>$value],array_values($values))];
        $handle='cos-'.substr(hash('sha256',$org.':'.$conn),0,12).'-'.$id;
        $description=trim((string)($native['description']??''));
        if(mb_strlen($description)>20000)throw new DomainException('Product description exceeds preview bound.');
        $input=['title'=>ProductRules::name((string)$native['name']),'handle'=>$handle,
            'descriptionHtml'=>'<p>'.htmlspecialchars($description,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>',
            'status'=>$publish?'ACTIVE':'DRAFT','productOptions'=>$productOptions,'variants'=>$variants];
        if($series){
            if($publish)throw new DomainException('Concept products may never publish.');
            $input['productType']='SERIES01';
            $input['tags']=['cos-managed','series01','concept-not-for-sale'];
        }
        return ['kind'=>'product_set_draft','organization_id'=>$org,'connection_id'=>$conn,'product_id'=>$id,
            'canonical_currency'=>$currency,'native_revision'=>(int)$native['revision'],'native_variants'=>$identities,
            'query'=>self::PRODUCT_SET,'variables'=>['input'=>$input],'requires_approved_action'=>true,'publishable'=>$publish];
    }

    /** Exact complete independent readback, not the mutation response. */
    public static function verifyCatalogReadback(array $remote,array $plan):array
    {
        $input=$plan['variables']['input'];$nodes=$remote['variants']['nodes']??null;
        $gid=$remote['id']??null;
        if(!is_string($gid)||!preg_match('#^gid://shopify/Product/[0-9]+$#D',$gid)
            ||($remote['handle']??null)!==$input['handle']||($remote['status']??null)!==$input['status']
            ||($remote['title']??null)!==$input['title']||($remote['descriptionHtml']??null)!==$input['descriptionHtml']||!is_array($nodes)
            ||($remote['variants']['pageInfo']['hasNextPage']??null)!==false
            ||count($nodes)!==count($plan['native_variants']))throw new DomainException('Incomplete or nonmatching Product readback.');
        $bySku=[];$gids=[];
        foreach($nodes as $row){
            $sku=$row['sku']??null;$vgid=$row['id']??null;
            if(!is_string($sku)||isset($bySku[$sku])||!is_string($vgid)||isset($gids[$vgid])
                ||!preg_match('#^gid://shopify/ProductVariant/[0-9]+$#D',$vgid))throw new DomainException('Invalid readback variant identity.');
            $bySku[$sku]=$row;$gids[$vgid]=true;
        }
        $mapping=[];$i=0;
        foreach($plan['native_variants'] as $id=>$row){
            $observed=$bySku[$row['sku']]??null;
            if(!is_array($observed)||self::decimal((string)($observed['price']??''))!==self::decimal($row['price']))
                throw new DomainException('Readback SKU or price mismatch.');
            $actualOptions=$observed['selectedOptions']??null;
            if(!is_array($actualOptions))throw new DomainException('Selected variant options missing.');
            $actual=array_column($actualOptions,'value','name');
            $expected=array_column($input['variants'][$i]['optionValues'],'name','optionName');
            ksort($actual,SORT_STRING);ksort($expected,SORT_STRING);
            if($actual!==$expected||count($actualOptions)!==count($expected))throw new DomainException('Selected variant options differ.');
            $mapping[$id]=$observed['id'];++$i;
        }
        return ['product_gid'=>$gid,'variants'=>$mapping];
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
            ||self::decimal((string)($product['variants']['nodes'][0]['price']??''))!==self::decimal($expectedPriceUsd)) {
            throw new DomainException('Shopify returned nonmatching product; quarantine instead of remapping.');
        }
        return $gid;
    }

    private static function decimal(string $value):string
    {
        if(!preg_match('/^[0-9]+(?:\.[0-9]{1,6})?$/D',$value))throw new DomainException('Invalid provider price.');
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
        return (ltrim($whole,'0')?:'0').'.'.rtrim($fraction,'0');
    }

    private static function scope(string $org,string $conn):void
    {
        if($org===''||strlen($org)>40||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/D',$org)
            ||!preg_match('/^[a-f0-9]{32}$/D',$conn)) {
            throw new DomainException('Outbound Shopify request requires valid tenant and connection IDs.');
        }
    }
}
