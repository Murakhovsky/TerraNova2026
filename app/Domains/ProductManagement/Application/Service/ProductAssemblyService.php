<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Application\Service;

use Domains\ProductManagement\Application\Contract\ProductAssemblyTransactionPort;
use Domains\ProductManagement\Domain\ProductRules;
use DomainException;

/** Creates a complete draft assortment using only existing Product-owned mutations.
 * No publication, inventory adjustment, provider call or cross-domain write.
 * The parent receipt and all child receipts/events commit or roll back together.
 */
final readonly class ProductAssemblyService
{
    public function __construct(private ProductAssemblyTransactionPort $transaction,private ProductManagementService $products,
        private PricingService $prices,private ProductMediaService $media){}
    public static function childKey(string $key,string $label):string
    {
        return 'pm-assemble:'.hash('sha256',$key."\0".$label);
    }
    public function assemble(string $org,array $input,string $key,string $actor,string $trace):array
    {
        if($org===''||strlen($org)>40||$key===''||strlen($key)>191||$actor===''||$trace===''
            ||!is_string($input['name']??null)||!is_array($input['variants']??null)||!array_is_list($input['variants'])
            ||count($input['variants'])<1||count($input['variants'])>10)throw new DomainException('Invalid bounded Product assembly.');
        $variants=$input['variants'];$seen=[];
        foreach($variants as $v){
            $sku=ProductRules::sku($v['sku']);
            if(isset($seen[$sku]))throw new DomainException('Duplicate assembly SKU.');
            $seen[$sku]=true;
        }
        $categories=$input['category_ids']??[];$attrs=$input['attributes']??[];$images=$input['media']??[];
        if(count($categories)>10||count(array_unique($categories))!==count($categories)||count($attrs)>20||count($images)>10)
            throw new DomainException('Assembly scope exceeds bounds.');
        return $this->transaction->assemble($org,$input,$key,$actor,$trace,function()use($org,$input,$variants,$categories,$attrs,$images,$key,$actor,$trace):array{
            $child=static fn(string $label):string=>self::childKey($key,$label);
            $type=$input['type']??'stock';
            $defaultTrack=in_array($type,['stock','material'],true);
            $first=$variants[0];
            $created=$this->products->createProduct($org,['name'=>$input['name'],'type'=>$type,'sku'=>$first['sku'],
                'uom'=>$first['uom']??'pcs','track_inventory'=>$first['track_inventory']??$defaultTrack],$child('product'),$actor,$trace);
            $pid=$created['product_id'];$ids=[];
            foreach($variants as $i=>$v){
                $sku=ProductRules::sku($v['sku']);
                $vid=$i===0?$created['variant_id']:$this->products->createVariant($org,$pid,$sku,$v['uom']??'pcs',
                    $v['track_inventory']??$defaultTrack,$child('variant:'.$i),$actor,$trace)['variant_id'];
                $ids[$sku]=$vid;
                if(isset($v['price']))$this->prices->set($org,$vid,$v['price']['currency'],$v['price']['amount'],$child('price:'.$i),$actor,$trace,0);
            }
            foreach($categories as $i=>$id)$this->products->assignCategory($org,$pid,$id,$child('category:'.$i),$actor,$trace);
            if($attrs!==[]){
                $revision=$this->products->productAttributes($org,$pid)['revision'];
                $this->products->setAttributes($org,$pid,$attrs,$revision,$child('attributes'),$actor,$trace);
            }
            $mediaIds=[];
            foreach($images as $i=>$image){
                $vid=null;
                if(isset($image['sku'])){
                    $sku=ProductRules::sku($image['sku']);
                    if(!isset($ids[$sku]))throw new DomainException('Media SKU is not part of assembly.');
                    $vid=$ids[$sku];
                }
                $mediaIds[]=$this->media->attach($org,$pid,$image['file_id'],$vid,$image['role']??'gallery',
                    $image['sort_order']??0,$child('media:'.$i),$actor,$trace)['media_id'];
            }
            return ['product_id'=>$pid,'variant_id'=>$created['variant_id'],'sku'=>$created['sku'],
                'variants'=>array_map(static fn($sku,$id)=>['sku'=>(string)$sku,'variant_id'=>$id],array_keys($ids),array_values($ids)),
                'media_ids'=>$mediaIds,'status'=>'draft'];
        });
    }
}
