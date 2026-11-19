<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Domains\ProductManagement\Domain\ProductRules;

/** Pure snapshot-to-canonical candidate normalization; never writes Product/Stock. */
final class ShopifyImportCandidateNormalizer
{

    /**
     * Stable fingerprint of a JSON value after MySQL's JSON key reordering.
     * Array list order is meaningful, object key order is not.
     */
    public static function fingerprint(array $candidate):string
    {
        $normalize=static function(mixed $value)use(&$normalize):mixed{
            if(!is_array($value))return $value;
            if(!array_is_list($value))ksort($value,SORT_STRING);
            foreach($value as $key=>$item)$value[$key]=$normalize($item);
            return $value;
        };
        return hash('sha256',json_encode($normalize($candidate),
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public static function normalize(array $snapshot,string $currency):array
    {
        $gid=$snapshot['id']??null;
        if(!is_string($gid)||!preg_match('#^gid://shopify/Product/[0-9]+$#D',$gid)
            ||!preg_match('/^[A-Z]{3}$/D',$currency)
            ||!in_array($snapshot['status']??null,['ACTIVE','DRAFT'],true)) {
            throw new DomainException('Shopify import requires Product GID, shop currency and supported status.');
        }
        $name=ProductRules::name((string)($snapshot['title']??''));
        $connection=$snapshot['variants']??null;
        if(!is_array($connection)||!is_array($connection['nodes']??null)
            ||!array_is_list($connection['nodes'])||count($connection['nodes'])<1
            ||count($connection['nodes'])>100
            ||($connection['pageInfo']['hasNextPage']??null)!==false) {
            throw new DomainException('Shopify candidate has missing or incomplete variants.');
        }
        $variants=[];$seenGids=[];$seenSkus=[];
        foreach($connection['nodes'] as $item) {
            if(!is_array($item))throw new DomainException('Malformed Shopify candidate variant.');
            $variantGid=$item['id']??null;
            if(!is_string($variantGid)
                ||!preg_match('#^gid://shopify/ProductVariant/[0-9]+$#D',$variantGid)
                ||isset($seenGids[$variantGid])) {
                throw new DomainException('Missing or duplicate Shopify Variant GID.');
            }
            $seenGids[$variantGid]=true;
            $sku=ProductRules::sku((string)($item['sku']??''));
            if(isset($seenSkus[$sku]))throw new DomainException('Repeated canonical SKU in Shopify Product.');
            $seenSkus[$sku]=true;
            $amount=$item['price']??null;
            if(!is_string($amount)&&!is_int($amount)) {
                throw new DomainException('Shopify Variant price must be a decimal string.');
            }
            $price=ProductRules::money((string)$amount);
            $inventory=$item['inventoryItem']??null;
            if(!is_array($inventory)||!is_bool($inventory['tracked']??null)
                ||!is_string($inventory['id']??null)
                ||!preg_match('#^gid://shopify/InventoryItem/[0-9]+$#D',$inventory['id'])
                ||($inventory['inventoryLevels']['pageInfo']['hasNextPage']??null)!==false) {
                throw new DomainException('Shopify Variant inventory pagination incomplete.');
            }
            $barcode=$item['barcode']??null;
            if($barcode!==null && (!is_string($barcode)||strlen($barcode)>128
                ||($barcode!==''&&!preg_match('/^[A-Za-z0-9._:\/-]+$/D',$barcode)))) {
                throw new DomainException('Invalid Shopify barcode.');
            }
            $variants[]=[
                'gid'=>$variantGid,'sku'=>$sku,'uom'=>'pcs',
                'track_inventory'=>$inventory['tracked'],
                'inventory_item_gid'=>$inventory['id'],
                'barcode'=>$barcode?:null,
                'price'=>$price,'currency'=>$currency,
            ];
        }
        return ['gid'=>$gid,'name'=>$name,'type'=>'stock',
            'remote_status'=>$snapshot['status'],'currency'=>$currency,'variants'=>$variants];
    }
}
