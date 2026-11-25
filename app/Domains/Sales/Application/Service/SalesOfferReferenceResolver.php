<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\SalesOfferReferencePort;
use Domains\ProductManagement\Application\Contract\ProductCommercialReferencePort;
use Domains\Property\Contract\PropertyReferencePort;
use Kernel\Module\ActiveModuleResolver;

final readonly class SalesOfferReferenceResolver implements SalesOfferReferencePort
{
    public function __construct(private ProductCommercialReferencePort $products,private PropertyReferencePort $properties,
        private ActiveModuleResolver $modules){}
    public function resolve(string $org,array $line):?array
    {
        if ($line['kind']==='service') return ['kind'=>'service','title'=>$line['title']];
        if ($line['kind']==='product') {
            if (!$this->modules->isEnabled($org,'product_management')) return null;
            return $this->products->quoteReference($org,$line['product_id'],$line['variant_id']);
        }
        if (!$this->modules->isEnabled($org,'property')) return null;
        $row=$this->properties->getInventorySnapshot($org,$line['inventory_id']);
        if ($row===null||($row['inventory_id']??null)!==$line['inventory_id']||!in_array($row['status']??null,['available','under_offer'],true)) return null;
        $asset=$this->properties->getPropertyReference($org,(string)$row['asset_id']);
        if ($asset===null||($asset['asset_id']??null)!==$row['asset_id'])return null;
        return ['inventory_id'=>$row['inventory_id'],'asset_id'=>$row['asset_id'],'transaction_type'=>$row['transaction_type'],
            'status'=>$row['status'],'type_code'=>$asset['type_code'],'asset_lifecycle'=>$asset['lifecycle'],'price_amount'=>$row['price_amount'],'price_currency'=>$row['price_currency'],'price_period'=>$row['price_period'],'available_from'=>$row['available_from'],'available_until'=>$row['available_until'],'observed_updated_at'=>$row['updated_at']];
    }
}
