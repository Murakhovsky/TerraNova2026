<?php
declare(strict_types=1);

namespace App\Persistence\ProductManagement;

use DomainException;
use Kernel\Llm\StructuredLlmRequest;

/** Synthetic, bounded business canary; never accepts arbitrary model-generated mutations. */
final class ProductAiAcceptanceScenario
{
    public const USE_CASE='product_management.acceptance.live_ai';
    public const CAPABILITY='product_management.governed.product.assemble';

    public static function correlation(string $nonce):string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$nonce))throw new DomainException('Invalid canary nonce.');
        return 'pm-ai-acceptance-'.$nonce;
    }

    public static function request(string $org,string $nonce):StructuredLlmRequest
    {
        $expected=self::expected($nonce);
        $p=$expected['operations'][0]['parameters'];
        $obj=static fn(array $properties):array=>['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false];
        $string=['type'=>'string'];
        $variant=$obj(['sku'=>$string,'uom'=>$string,'track_inventory'=>['type'=>'boolean'],
            'price'=>$obj(['currency'=>$string,'amount'=>$string])]);
        $operation=$obj(['id'=>$string,'capability_id'=>$string,'parameters'=>$obj([
            'name'=>$string,'type'=>$string,'variants'=>['type'=>'array','items'=>$variant],
        ])]);
        return new StructuredLlmRequest(
            'You are a COS proposal-only planner. Produce a JSON envelope with kind product_management.governed_plan.v1, description, and operations. Use only the supplied approved-action capability. Do not execute, approve, publish, alter inventory, invent references, or include other fields.',
            'Перетвори бізнес-завдання на один керований Product Action: створити тестову чернетку товару з двома SKU, одиницею pcs, відстеженням залишків і двома базовими цінами USD. Точно використай надані назву, опис, SKU, ціни, тип та ID кроку.',
            ['description'=>$expected['description'],'step_id'=>'assemble','allowed_capability'=>self::CAPABILITY,
                'product_name'=>$p['name'],'product_type'=>'stock','variants'=>[
                    ['sku'=>$p['variants'][0]['sku'],'amount'=>'12.50','currency'=>'USD'],
                    ['sku'=>$p['variants'][1]['sku'],'amount'=>'18.75','currency'=>'USD'],
                ]],
            $obj(['kind'=>$string,'description'=>$string,'operations'=>['type'=>'array','items'=>$operation]]),
            maxOutputTokens:900,maxCostAmount:0.10,organizationId:$org,useCase:self::USE_CASE,correlationId:self::correlation($nonce),
        );
    }

    public static function expected(string $nonce):array
    {
        self::correlation($nonce);
        $prefix='P6-'.strtoupper($nonce);
        $variant=static fn(string $suffix,string $amount):array=>['sku'=>$prefix.'-'.$suffix,'uom'=>'pcs',
            'track_inventory'=>true,'price'=>['currency'=>'USD','amount'=>$amount]];
        return ['kind'=>'product_management.governed_plan.v1','description'=>'P6 acceptance '.$nonce,
            'operations'=>[['id'=>'assemble','capability_id'=>self::CAPABILITY,'parameters'=>[
                'name'=>'P6 acceptance '.$nonce,'type'=>'stock',
                'variants'=>[$variant('A','12.50'),$variant('B','18.75')],
            ]]]];
    }

    /** Exact semantic contract, insensitive to object key order, never substitutes the expected plan for the model output. */
    public static function validate(array $output,string $nonce):array
    {
        if(self::canonical($output)!==self::canonical(self::expected($nonce)))
            throw new DomainException('Live model did not produce the exact bounded acceptance proposal.');
        return $output;
    }

    public static function digest(array $value):string
    {return hash('sha256',self::canonical($value));}

    private static function canonical(array $value):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{
            if(!is_array($v))return $v;
            if(!array_is_list($v))ksort($v,SORT_STRING);
            foreach($v as $k=>$child)$v[$k]=$sort($child);
            return $v;
        };
        return json_encode($sort($value),JSON_THROW_ON_ERROR);
    }
}
