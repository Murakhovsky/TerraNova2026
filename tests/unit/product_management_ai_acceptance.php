<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/app/Kernel/Llm/StructuredLlmRequest.php';
require $root.'/symfony/src/Persistence/ProductManagement/ProductAiAcceptanceScenario.php';

use App\Persistence\ProductManagement\ProductAiAcceptanceScenario as Scenario;

$nonce=str_repeat('a',32);
$expected=Scenario::expected($nonce);
$request=Scenario::request('staging-pm',$nonce);
if($request->organizationId!=='staging-pm'||$request->useCase!==Scenario::USE_CASE
    ||$request->correlationId!==Scenario::correlation($nonce)||$request->maxCostAmount!==0.10
    ||Scenario::validate(array_reverse($expected,true),$nonce)!==array_reverse($expected,true))
    throw new RuntimeException('Product AI canary request or immutable semantic contract failed.');
$bad=[];
$x=$expected;$x['operations'][0]['capability_id']='product_management.product.create';$bad[]=$x;
$x=$expected;$x['operations'][0]['parameters']['variants'][0]['price']['amount']='1';$bad[]=$x;
$x=$expected;$x['operations'][0]['parameters']['organization_id']='foreign';$bad[]=$x;
$x=$expected;$x['operations'][]=$x['operations'][0];$bad[]=$x;
$x=$expected;$x['approval']='approved';$bad[]=$x;
$x=$expected;$x['operations'][0]['parameters']['variants']=array_reverse($x['operations'][0]['parameters']['variants']);$bad[]=$x;
foreach($bad as $value){
    try{Scenario::validate($value,$nonce);}catch(DomainException){continue;}
    throw new RuntimeException('Unsafe model output accepted.');
}
echo "Product Management P6 model proposal contract PASS (fixture; not live).\n";
