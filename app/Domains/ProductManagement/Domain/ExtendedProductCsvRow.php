<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Domain;

use DomainException;

/** Backward-compatible extended CSV row grammar. All JSON cells are data, never executable steps. */
final class ExtendedProductCsvRow
{
    public const HEADER=['sku','name','type','uom','track_inventory','price_currency','price_amount','category_ids_json','attributes_json','media_json','variants_json'];

    public static function assembly(array $base,array $fields):array
    {
        $first=['sku'=>$base['sku'],'uom'=>$base['uom'],'track_inventory'=>$base['track_inventory']];
        $currency=trim($fields[5]);$amount=trim($fields[6]);
        if(($currency==='')!==($amount===''))throw new DomainException('Price currency and amount must be provided together.');
        if($currency!=='')$first['price']=['currency'=>$currency,'amount'=>$amount];
        $variants=array_merge([$first],self::list($fields[10],9));
        $categories=self::list($fields[7],10);$attributes=self::list($fields[8],20);$media=self::list($fields[9],10);
        $type=$base['type'];$defaultTrack=in_array($type,['stock','material'],true);
        $seen=[];
        foreach($variants as &$v){
            if(!is_array($v)||array_is_list($v)||array_diff(array_keys($v),['sku','uom','track_inventory','price'])!==[]
                ||!is_string($v['sku']??null))throw new DomainException('Variant requires explicit SKU and supported fields.');
            $v['sku']=ProductRules::sku($v['sku']);
            $v['uom']=$v['uom']??'pcs';$v['track_inventory']=$v['track_inventory']??$defaultTrack;
            if(isset($seen[$v['sku']])||!is_string($v['uom'])||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,15}$/D',$v['uom'])
                ||!is_bool($v['track_inventory'])||(!$defaultTrack&&$v['track_inventory']))throw new DomainException('Duplicate SKU or invalid UOM/inventory mode.');
            $seen[$v['sku']]=true;
            if(array_key_exists('price',$v)){
                $p=$v['price'];
                if(!is_array($p)||count($p)!==2||!is_string($p['currency']??null)||!is_string($p['amount']??null)
                    ||!preg_match('/^[A-Za-z]{3}$/D',$p['currency']))throw new DomainException('Invalid per-SKU price.');
                $v['price']=['currency'=>strtoupper($p['currency']),'amount'=>ProductRules::money($p['amount'])];
            }
        }unset($v);
        foreach($categories as $c)if(!is_string($c)||!preg_match('/^[a-f0-9]{32}$/D',$c))throw new DomainException('Invalid category identity.');
        if(count(array_unique($categories))!==count($categories))throw new DomainException('Duplicate category.');
        foreach(AttributeRules::changes($attributes===[]?[['code'=>'empty','value'=>null]]:$attributes) as $value)
            if($value!==null&&!is_string($value)&&!is_bool($value))throw new DomainException('Invalid typed attribute.');
        foreach($media as &$image){
            if(!is_array($image)||array_diff(array_keys($image),['file_id','sku','role','sort_order'])!==[]
                ||!is_string($image['file_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$image['file_id']))
                throw new DomainException('Invalid Documents image reference.');
            if(array_key_exists('sku',$image)){
                if(!is_string($image['sku']))throw new DomainException('Invalid image SKU.');
                $image['sku']=ProductRules::sku($image['sku']);
                if(!isset($seen[$image['sku']]))throw new DomainException('Image SKU must be in this Product.');
            }
            $image['role']=$image['role']??'gallery';$image['sort_order']=$image['sort_order']??0;
            if(!in_array($image['role'],['cover','gallery'],true)||!is_int($image['sort_order'])||$image['sort_order']<0||$image['sort_order']>100000)
                throw new DomainException('Invalid image role or order.');
        }unset($image);
        return ['name'=>$base['name'],'type'=>$type,'variants'=>$variants,'category_ids'=>$categories,'attributes'=>$attributes,'media'=>$media];
    }
    private static function list(string $value,int $max):array
    {
        if(trim($value)==='')return [];
        $decoded=json_decode($value,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($decoded)||!array_is_list($decoded)||count($decoded)>$max)throw new DomainException('Expected bounded JSON list.');
        return $decoded;
    }
}
