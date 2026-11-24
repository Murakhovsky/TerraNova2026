<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingProductDraftReadPort;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/**
 * Product truth is read from ProductManagement. Result is a draft planning check,
 * never ad-policy approval, fulfillment commitment, inventory guarantee or Meta write.
 */
final readonly class MarketingProductDraftCheckService
{
    public function __construct(private MarketingProductDraftReadPort $products){}

    /** @return array<string,mixed> */
    public function search(TenantContext $actor,string $query='',int $limit=20,int $offset=0):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)){
            throw new DomainException('Marketing manager required for Product selection.');
        }
        $query=trim($query);
        if(mb_strlen($query)>200||$limit<1||$limit>20||$offset<0||$offset>1000000){
            throw new DomainException('Invalid Product selection bounds.');
        }
        $page=$this->products->search($actor->organizationId()->value(),$query,$limit,$offset);
        if(!is_array($page['items']??null)||!array_is_list($page['items'])||count($page['items'])>$limit||!is_bool($page['has_more']??null)){
            throw new DomainException('Untrusted Product search page.');
        }
        $items=[];$seen=[];
        foreach($page['items'] as $row){
            if(!is_array($row))throw new DomainException('Untrusted Product search row.');
            foreach(['product_ref','variant_ref','name','sku','product_status'] as $key){
                if(!is_string($row[$key]??null))throw new DomainException('Untrusted Product search field.');
            }
            if(!preg_match('/^[a-f0-9]{32}$/D',$row['product_ref'])||!preg_match('/^[a-f0-9]{32}$/D',$row['variant_ref'])
                ||!in_array($row['product_status'],['active','draft'],true)||isset($seen[$row['variant_ref']])
                ||mb_strlen($row['name'])<1||mb_strlen($row['name'])>250||mb_strlen($row['sku'])<1||mb_strlen($row['sku'])>191){
                throw new DomainException('Untrusted Product selection reference.');
            }
            $seen[$row['variant_ref']]=true;
            $items[]=['product_ref'=>$row['product_ref'],'variant_ref'=>$row['variant_ref'],'name'=>$row['name'],
                'sku'=>$row['sku'],'product_status'=>strtoupper($row['product_status']),'launch_eligible'=>false];
        }
        return ['items'=>$items,'limit'=>$limit,'offset'=>$offset,'has_more'=>$page['has_more']&&$offset+$limit<=1000000,
            'source_domain'=>'product_management'];
    }

    /** @return array<string,mixed> */
    public function check(TenantContext $actor,string $productId,?string $variantId,string $currency):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)
            ||!$actor->allows(TenantPermissions::MANAGE)){
            throw new DomainException('Marketing manager required to check Product draft references.');
        }
        if(!preg_match('/^[a-f0-9]{32}$/D',$productId)
            ||($variantId!==null&&!preg_match('/^[a-f0-9]{32}$/D',$variantId))
            ||!preg_match('/^[A-Z]{3}$/D',$currency)){
            throw new DomainException('Product draft check requires canonical Product/Variant references and currency.');
        }
        $found=$this->products->inspect($actor->organizationId()->value(),$productId,$variantId,$currency);
        if($found===null){
            return $this->result($productId,$variantId,$currency,'NOT_FOUND','NOT_FOUND',0,0,null,false);
        }
        if(($found['product_ref']??null)!==$productId
            ||($found['variant_ref']??null)!==$variantId
            ||($found['currency']??null)!==$currency
            ||!is_int($found['product_revision']??null)
            ||!is_int($found['variant_revision']??null)
            ||!is_string($found['product_status']??null)
            ||!is_string($found['variant_status']??null)
            ||!array_key_exists('base_price_decimal',$found)
            ||($found['base_price_decimal']!==null&&!is_string($found['base_price_decimal']))){
            throw new DomainException('Product owner supplied a mismatched or untrusted draft readback.');
        }
        $productStatus=match($found['product_status']){'active'=>'ACTIVE','draft'=>'DRAFT',default=>'NOT_ACTIVE'};
        $variantStatus=$variantId===null?'NOT_SELECTED':($found['variant_status']==='active'?'ACTIVE':'NOT_ACTIVE');
        $basePrice=$found['base_price_decimal'];
        if($basePrice!==null&&!preg_match('/^(0|[1-9][0-9]{0,15})(?:\.[0-9]{1,6})?$/D',$basePrice)){
            throw new DomainException('Product owner returned a noncanonical decimal price.');
        }
        $canDraft=in_array($productStatus,['ACTIVE','DRAFT'],true)
            &&($variantId===null||$variantStatus==='ACTIVE');
        return $this->result($productId,$variantId,$currency,$productStatus,$variantStatus,
            $found['product_revision'],$found['variant_revision'],$basePrice,$canDraft);
    }

    /** @return array<string,mixed> */
    private function result(string $productId,?string $variantId,string $currency,
        string $productStatus,string $variantStatus,int $productRevision,int $variantRevision,
        ?string $price,bool $canDraft):array
    {
        $pending=['DESTINATION_URL','META_AD_POLICY','FINANCE_ENVELOPE_AND_APPROVAL'];
        if($variantId===null)$pending[]='VARIANT_SELECTION';
        if($variantId!==null&&$price===null)$pending[]='BASE_PRICE';
        $pending[]='INVENTORY_LOCATION_AND_STOCK';
        return [
            'source_domain'=>'product_management',
            'product_ref'=>$productId,
            'variant_ref'=>$variantId,
            'currency'=>$currency,
            'product_status'=>$productStatus,
            'variant_status'=>$variantStatus,
            'product_revision'=>$productRevision,
            'variant_revision'=>$variantRevision,
            'base_price_decimal'=>$price,
            'can_reference_in_draft'=>$canDraft,
            'launch_eligible'=>false,
            'inventory_verified'=>false,
            'external_destination_verified'=>false,
            'provider_ad_policy_verified'=>false,
            'pending_checks'=>$pending,
            'source_readback'=>'PRODUCT_DOMAIN_CURRENT_READ',
        ];
    }
}
