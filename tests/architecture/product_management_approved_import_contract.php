<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$def=require $root.'/app/Domains/ProductManagement/module.php';
$items=array_values(array_filter($def['contributions']['capability_contracts'],
    static fn(array $x):bool=>($x['id']??null)==='product_management.import.apply'));
if(count($items)!==1)throw new RuntimeException('Exactly one approved Product CSV Action contract required.');
$cap=$items[0];
foreach(['execution_binding'=>'action:product_management.import.apply','side_effect_level'=>'external',
        'approval_policy'=>'required','idempotency'=>'required'] as $key=>$expected){
    if(($cap[$key]??null)!==$expected)throw new RuntimeException('CSV Action contract bypass: '.$key);
}
if(($cap['retry_policy']['max_attempts']??null)!==1)throw new RuntimeException('CSV Action must not auto retry.');
$handler=$read('app/Domains/ProductManagement/Automation/Action/ProductImportApplyHandler.php');
$module=$read('app/Domains/ProductManagement/Bootstrap/ProductManagementDomainModule.php');
$csv=$read('app/Domains/ProductManagement/Application/Service/CsvProductImportService.php');
if(!str_contains($handler,'IdempotentExternalActionHandlerInterface')
    ||!str_contains($handler,'ActionStatus::Running')
    ||!str_contains($handler,"'APPROVAL_REQUIRED'")
    ||!str_contains($handler,"'HIGH'")
    ||!str_contains($handler,'/^wf:[a-f0-9]{64}$/D')
    ||!str_contains($module,'ActionOwningModuleInterface')
    ||!str_contains($module,'PolicyDecision::ApprovalRequired')
    ||!str_contains($csv,'Immutable CSV staged row differs from approved evidence.')
    ||!str_contains($csv,'Manual native Product receipt reconciliation')
        &&!str_contains($csv,'manual native Product receipt reconciliation')) {
    throw new RuntimeException('Approved CSV apply does not respect canonical Action+immutable row boundaries.');
}
foreach(['import-apply-input.schema.json','import-apply-output.schema.json'] as $name){
    $schema=json_decode($read('resources/contracts/product-management/'.$name),true,flags:JSON_THROW_ON_ERROR);
    if(($schema['type']??null)!=='object')throw new RuntimeException('Missing Product CSV Action schema.');
}
echo "Product Management approved CSV immutable Action architecture passed.\n";
