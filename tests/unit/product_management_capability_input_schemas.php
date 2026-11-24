<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
use Kernel\Module\CapabilityContract;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
$raw=require $root.'/app/Domains/ProductManagement/module.php';
$contracts=[];
foreach($raw['contributions']['capability_contracts'] as $definition) {
    $cap=CapabilityContract::fromArray($definition);
    $contracts[$cap->id]=$cap;
}
if(count($contracts)!==44)throw new RuntimeException('Missing typed PM contracts.');
$validator=new CapabilityJsonInputValidator();
$good=[
 'product_management.price.list.entry.remove'=>['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'expected_revision'=>1],
 'product_management.price.list.tier.set'=>['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'min_quantity'=>'10.5','amount'=>null,'expected_revision'=>1],
 'product_management.price.list.tier.search'=>['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'limit'=>30],
 'product_management.price.resolve'=>['variant_id'=>str_repeat('b',32),'currency'=>'EUR','quantity'=>'12.5'],
 'product_management.stock.reservation.search'=>['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'limit'=>30],
 'product_management.stock.reserve'=>['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'quantity'=>'2.500000','source_ref'=>'manual:ORDER-1'],
 'product_management.stock.release'=>['reservation_id'=>str_repeat('a',32),'reason'=>'Order cancelled'],
 'product_management.price.list.update'=>['list_id'=>str_repeat('a',32),'name'=>'Renamed','expected_revision'=>1],
 'product_management.price.list.archive'=>['list_id'=>str_repeat('a',32),'expected_revision'=>2],
 'product_management.price.list.history.search'=>['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'limit'=>30],
 'product_management.price.list.create'=>['code'=>'B2B','name'=>'Wholesale','kind'=>'wholesale','currency'=>'EUR'],
 'product_management.price.list.search'=>['code'=>'B2B','limit'=>10],
 'product_management.price.list.entry.get'=>['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32)],
 'product_management.price.list.entry.set'=>['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'amount'=>'12.3456','expected_revision'=>0],
 'product_management.price.history.search'=>['variant_id'=>str_repeat('a',32),'currency'=>'EUR','limit'=>30,'offset'=>0],
 'product_management.price.base.set'=>['variant_id'=>str_repeat('a',32),'currency'=>'EUR','amount'=>'12.3400','expected_revision'=>0],
 'product_management.media.attach'=>['product_id'=>str_repeat('a',32),'file_id'=>'FILE-123',
    'variant_id'=>str_repeat('b',32),'role'=>'cover','sort_order'=>10],
 'product_management.media.detach'=>['media_id'=>str_repeat('a',32)],
 'product_management.media.search'=>['product_id'=>str_repeat('a',32),'limit'=>20],
 'product_management.translation.upsert'=>['product_id'=>str_repeat('a',32),'locale'=>'uk','name'=>'Українська назва',
     'description'=>'Опис товару'],
 'product_management.category.create'=>['name'=>'Components','slug'=>'components'],
 'product_management.category.assign'=>['product_id'=>str_repeat('a',32),'category_id'=>str_repeat('b',32)],
 'product_management.category.search'=>['limit'=>100,'offset'=>0],
 'product_management.location.search'=>['q'=>'WH','limit'=>20,'offset'=>0],
 'product_management.stock.receive'=>['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),
     'quantity'=>'1.125000','source_ref'=>'PO-123'],
 'product_management.stock.transfer'=>['variant_id'=>str_repeat('a',32),'from_location_id'=>str_repeat('b',32),
 'to_location_id'=>str_repeat('c',32),'quantity'=>'1.25','source_ref'=>'MOVE-1'],
 'product_management.stock.issue'=>['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),
     'quantity'=>'3','source_ref'=>'ORDER-123'],
 'product_management.variant.update'=>['variant_id'=>str_repeat('a',32),'expected_revision'=>1,'barcode'=>'1234567',
    'options'=>[['name'=>'color','value'=>'blue'],['name'=>'size','value'=>'L']]],
 'product_management.variant.archive'=>['variant_id'=>str_repeat('a',32),'expected_revision'=>2],
 'product_management.product.update'=>['product_id'=>str_repeat('a',32),'expected_revision'=>2,'name'=>'Updated'],
 'product_management.import.preview'=>['csv'=>"sku,name,type,uom,track_inventory\nA,Test,stock,pcs,1\n"],
];
foreach($good as $id=>$input) $validator->validate($contracts[$id],$input);
$bad=[
 'product_management.price.list.entry.remove'=>[['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'expected_revision'=>0]],
 'product_management.price.list.tier.set'=>[['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'min_quantity'=>'-1','amount'=>'3','expected_revision'=>0],['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'min_quantity'=>'1','amount'=>3,'expected_revision'=>0]],
 'product_management.price.resolve'=>[['variant_id'=>str_repeat('b',32),'currency'=>'EUR','quantity'=>'1','organization_id'=>'foreign']],
 'product_management.stock.reservation.search'=>[['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'limit'=>101],['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'organization_id'=>'other']],
 'product_management.stock.reserve'=>[['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'quantity'=>'1','source_ref'=>'sales:FORGED'],['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'quantity'=>'-1','source_ref'=>'manual:X']],
 'product_management.stock.release'=>[['reservation_id'=>'invalid','reason'=>'X'],['reservation_id'=>str_repeat('a',32),'reason'=>'X','actor_id'=>'forged']],
 'product_management.price.list.update'=>[['list_id'=>str_repeat('a',32),'name'=>'X','expected_revision'=>0],['list_id'=>str_repeat('a',32),'name'=>'X','expected_revision'=>1,'currency'=>'USD']],
 'product_management.price.list.archive'=>[['list_id'=>str_repeat('a',32),'expected_revision'=>0]],
 'product_management.price.list.history.search'=>[['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'offset'=>-1]],
 'product_management.price.list.create'=>[['code'=>'B2B','name'=>'Wholesale','kind'=>'invalid','currency'=>'EUR']],
 'product_management.price.list.search'=>[['limit'=>101],['organization_id'=>'other']],
 'product_management.price.list.entry.set'=>[['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'amount'=>'12.34567','expected_revision'=>0],['list_id'=>str_repeat('a',32),'variant_id'=>str_repeat('b',32),'amount'=>'12','expected_revision'=>-1]],
 'product_management.price.history.search'=>[['variant_id'=>str_repeat('a',32),'currency'=>'EUR','limit'=>101],['variant_id'=>str_repeat('a',32),'currency'=>'EUR','organization_id'=>'other'],['variant_id'=>str_repeat('a',32),'currency'=>'EUR','offset'=>-1]],
 'product_management.price.base.set'=>[['variant_id'=>str_repeat('a',32),'currency'=>'EUR','amount'=>'1','expected_revision'=>-1],['variant_id'=>str_repeat('a',32),'currency'=>'EUR','amount'=>'1','expected_revision'=>'0']],
 'product_management.media.attach'=>[['product_id'=>str_repeat('a',32),'file_id'=>'../outside','role'=>'cover']],
 'product_management.media.detach'=>[['media_id'=>'invalid']],
 'product_management.media.search'=>[['product_id'=>str_repeat('a',32),'limit'=>101]],
 'product_management.translation.upsert'=>[['product_id'=>str_repeat('a',32),'locale'=>'invalid','name'=>'X']],
 'product_management.category.create'=>[['name'=>'A','slug'=>'illegal space'],['name'=>'A','slug'=>'a','organization_id'=>'other']],
 'product_management.category.assign'=>[['product_id'=>'foo','category_id'=>str_repeat('b',32)]],
 'product_management.location.search'=>[['limit'=>101],['q'=>'ok','organization_id'=>'foreign']],

 'product_management.stock.transfer'=>[['variant_id'=>str_repeat('a',32),'from_location_id'=>'invalid',
 'to_location_id'=>str_repeat('b',32),'quantity'=>'1','source_ref'=>'MOVE-1']],
 'product_management.stock.receive'=>[['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'quantity'=>'-1','source_ref'=>'X'],
  ['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'quantity'=>'0.0000001','source_ref'=>'X']],
 'product_management.variant.update'=>[['variant_id'=>str_repeat('a',32),'expected_revision'=>1,'options'=>[['name'=>'color','value'=>['nested'=>'invalid']]]]],
 'product_management.variant.archive'=>[['variant_id'=>str_repeat('a',32),'expected_revision'=>0]],
 'product_management.product.update'=>[['product_id'=>str_repeat('a',32),'expected_revision'=>0]],
 'product_management.import.preview'=>[['csv'=>str_repeat('x',12001)]],
];
foreach($bad as $id=>$cases) foreach($cases as $input){
 try{$validator->validate($contracts[$id],$input);}catch(DomainException){continue;}
 throw new RuntimeException('Fail-closed capability schema bypass: '.$id);
}
echo "Product Management Capability Schema validator passed.\n";
