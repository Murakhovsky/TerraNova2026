<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\ModuleCatalog;

$catalog=new ModuleCatalog((new ModuleDiscovery($root.'/app/Domains'))->discover());
$pm=$catalog->definition('product_management');
$contracts=$pm->contributions->capabilityContracts;
$expected=['media.attach','media.detach','translation.upsert','variant.create','variant.update','variant.archive','location.create','stock.receive','stock.issue','stock.transfer','price.base.set','import.preview','category.create','category.assign'];
foreach($expected as $operation){
    $id='product_management.'.$operation;
    $match=array_values(array_filter($contracts,static fn($x)=>$x->id===$id));
    if(count($match)!==1)throw new RuntimeException('Missing Product Management capability: '.$id);
    $contract=$match[0];
    if($contract->executionBinding!=='command:'.$id||$contract->sideEffectLevel!=='internal'
        ||$contract->idempotency!=='required'||$contract->approvalPolicy!=='conditional')
        throw new RuntimeException('Unsafe Product Management command declaration: '.$id);
    $input=json_decode((string)file_get_contents($root.'/'.$contract->inputSchema),true,flags:JSON_THROW_ON_ERROR);
    if(($input['additionalProperties']??true)!==false)
        throw new RuntimeException('Schema permits injected tenant/actor parameters: '.$id);
}
$handler=(string)file_get_contents($root.'/symfony/src/Application/ProductManagement/Command/ProductMutationCommandHandler.php');
foreach($expected as $operation){
    if(!str_contains($handler,"'".$operation."' => ")) throw new RuntimeException('Handler missing: '.$operation);
}
echo "Product Management mutation capability contracts passed.\n";
