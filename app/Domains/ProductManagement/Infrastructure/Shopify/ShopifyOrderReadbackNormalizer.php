<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

/**
 * S5 source evidence for Sales/Finance/Growth reconciliation.
 *
 * Normalizes independently fetched Shopify Admin GraphQL order data to a
 * bounded non-PII record. Never trusts a webhook to declare money paid.
 * Product Management does not create a Sales order or Finance settlement.
 */
final class ShopifyOrderReadbackNormalizer
{
    /** @param array<string,mixed> $response @return array<string,mixed> */
    public static function normalize(array $response,string $expectedOrderGid):array
    {
        self::orderGid($expectedOrderGid);
        if(($response['errors']??[])!==[]
            ||!is_array($response['data']??null)
            ||!is_array($response['data']['shop']??null)
            ||!is_array($response['data']['order']??null)) {
            throw new DomainException('Shopify Order readback missing or rejected.');
        }
        $data=$response['data'];
        $order=$data['order'];
        if(($order['id']??null)!==$expectedOrderGid) {
            throw new DomainException('Shopify readback Order identity mismatch.');
        }
        $created=$order['createdAt']??null;
        $updated=$order['updatedAt']??null;
        foreach([$created,$updated] as $date) {
            if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/D',$date)
                ||strtotime($date)===false) {
                throw new DomainException('Invalid Shopify Order event timestamp.');
            }
        }
        if(strtotime($updated)<strtotime($created)) {
            throw new DomainException('Shopify Order update predates creation.');
        }
        $financial=self::status($order['displayFinancialStatus']??null,'financial');
        $fulfillment=self::status($order['displayFulfillmentStatus']??null,'fulfillment');
        $shopCurrency=$data['shop']['currencyCode']??null;
        $money=$order['currentTotalPriceSet']['shopMoney']??null;
        if(!is_array($money)
            ||!is_string($shopCurrency)||!preg_match('/^[A-Z]{3}$/D',$shopCurrency)
            ||($money['currencyCode']??null)!==$shopCurrency) {
            throw new DomainException('Shopify Order money currency does not match the shop.');
        }
        $amount=$money['amount']??null;
        if(!is_string($amount)||!preg_match('/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?$/D',$amount)) {
            throw new DomainException('Invalid Shopify Order monetary amount.');
        }
        $connection=$order['lineItems']??null;
        if(!is_array($connection)||!is_array($connection['nodes']??null)
            ||!is_array($connection['pageInfo']??null)
            ||($connection['pageInfo']['hasNextPage']??null)!==false
            ||count($connection['nodes'])>100) {
            throw new DomainException('Shopify Order line items are missing or truncated.');
        }
        $items=[];$unique=[];
        foreach($connection['nodes'] as $node) {
            if(!is_array($node)) throw new DomainException('Malformed Shopify Order line item.');
            $lineGid=$node['id']??null;
            if(!is_string($lineGid)||!preg_match('#^gid://shopify/LineItem/[1-9][0-9]*$#D',$lineGid)
                ||isset($unique[$lineGid])) {
                throw new DomainException('Invalid or duplicated Shopify Order LineItem identity.');
            }
            $unique[$lineGid]=true;
            $quantity=$node['quantity']??null;
            if(!is_int($quantity)||$quantity<0||$quantity>1000000) {
                throw new DomainException('Invalid Shopify Order line item quantity.');
            }
            $sku=$node['sku']??null;
            if($sku!==null&&(!is_string($sku)||strlen($sku)>128)) {
                throw new DomainException('Invalid Shopify Order line SKU.');
            }
            $variantGid=$node['variant']['id']??null;
            if($variantGid!==null && (!is_string($variantGid)
                ||!preg_match('#^gid://shopify/ProductVariant/[1-9][0-9]*$#D',$variantGid))) {
                throw new DomainException('Invalid Shopify Order line Variant identity.');
            }
            $items[]=[
                'line_gid'=>$lineGid,
                'sku'=>$sku!==''?$sku:null,
                'variant_gid'=>$variantGid,
                'quantity'=>$quantity,
                'requires_product_mapping'=>$variantGid!==null,
            ];
        }
        if($items===[]) throw new DomainException('Empty Shopify Order line items need manual review.');
        usort($items,static fn(array $a,array $b):int=>strcmp($a['line_gid'],$b['line_gid']));
        $evidence=[
            'source'=>'shopify_admin_graphql_2026_10',
            'order_gid'=>$expectedOrderGid,
            'created_at'=>$created,
            'updated_at'=>$updated,
            'financial_status'=>$financial,
            'fulfillment_status'=>$fulfillment,
            'shop_currency'=>$shopCurrency,
            'current_total_shop_amount'=>$amount,
            'line_items'=>$items,
        ];
        return $evidence+[
            'snapshot_sha256'=>hash('sha256',json_encode($evidence,JSON_THROW_ON_ERROR)),
            'evidence_state'=>'observed_pending_sales_reconciliation',
            'payment_settled'=>false,
            'marketing_attribution_verified'=>false,
        ];
    }

    public static function orderGid(string $gid):void
    {
        if(!preg_match('#^gid://shopify/Order/[1-9][0-9]{0,18}$#D',$gid)) {
            throw new DomainException('Expected a bounded Shopify Order GID.');
        }
    }

    private static function status(mixed $value,string $kind):string
    {
        if(!is_string($value)||!preg_match('/^[A-Z_]{2,40}$/D',$value)) {
            throw new DomainException('Invalid Shopify '.$kind.' status.');
        }
        return $value;
    }
}
