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
if(count($contracts)!==19)throw new RuntimeException('Missing typed PM contracts.');
$validator=new CapabilityJsonInputValidator();
$good=[
 'product_management.category.create'=>['name'=>'Components','slug'=>'components'],
 'product_management.category.assign'=>['product_id'=>str_repeat('a',32),'category_id'=>str_repeat('b',32)],
 'product_management.category.search'=>['limit'=>100,'offset'=>0],
 'product_management.stock.receive'=>['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),
     'quantity'=>'1.125000','source_ref'=>'PO-123'],
 'product_management.stock.issue'=>['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),
     'quantity'=>'3','source_ref'=>'ORDER-123'],
 'product_management.product.update'=>['product_id'=>str_repeat('a',32),'expected_revision'=>2,'name'=>'Updated'],
 'product_management.import.preview'=>['csv'=>"sku,name,type,uom,track_inventory\nA,Test,stock,pcs,1\n"],
];
foreach($good as $id=>$input) $validator->validate($contracts[$id],$input);
$bad=[
 'product_management.category.create'=>[['name'=>'A','slug'=>'illegal space'],['name'=>'A','slug'=>'a','organization_id'=>'other']],
 'product_management.category.assign'=>[['product_id'=>'foo','category_id'=>str_repeat('b',32)]],
 'product_management.stock.receive'=>[['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'quantity'=>'-1','source_ref'=>'X'],
  ['variant_id'=>str_repeat('a',32),'location_id'=>str_repeat('b',32),'quantity'=>'0.0000001','source_ref'=>'X']],
 'product_management.product.update'=>[['product_id'=>str_repeat('a',32),'expected_revision'=>0]],
 'product_management.import.preview'=>[['csv'=>str_repeat('x',12001)]],
];
foreach($bad as $id=>$cases) foreach($cases as $input){
 try{$validator->validate($contracts[$id],$input);}catch(DomainException){continue;}
 throw new RuntimeException('Fail-closed capability schema bypass: '.$id);
}
echo "Product Management Capability Schema validator passed.\n";
