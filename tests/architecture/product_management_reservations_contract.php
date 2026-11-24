<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$store=(string)file_get_contents($root.'/app/Domains/ProductManagement/Infrastructure/Persistence/MySqlProductManagementStore.php');
$service=(string)file_get_contents($root.'/app/Domains/ProductManagement/Application/Service/ProductManagementService.php');
$manifest=require $root.'/app/Domains/ProductManagement/module.php';
$adr=(string)file_get_contents($root.'/docs/11-decisions/ADR-0017-product-management-reservations-ownership.md');
$assert=static function(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('E5 reservation architecture: '.$message);
};
$assert(str_contains($store,"'stock.reserve'") && str_contains($store,"'stock.release'")
    && str_contains($store,'FOR UPDATE')
    && str_contains($store,'reserved+CAST(:guard AS DECIMAL(20,6))<=on_hand')
    && str_contains($store,'reserved>=CAST(:guard AS DECIMAL(20,6))'),
    'CAS reservation/release and transactional fencing missing');
$assert(str_contains($service,'^manual:')
    && str_contains($adr,'SalesOrder/OrderLine')
    && !str_contains($store,'UPDATE sales_')
    && !str_contains($store,'UPDATE procurement_'),
    'Unapproved Sales/Procurement writer or ownership ambiguity');
foreach(['stock.reserve','stock.release'] as $operation){
    $id='product_management.'.$operation;
    $found=array_values(array_filter($manifest['contributions']['capability_contracts'],
        static fn(array $x):bool=>($x['id']??null)===$id));
    $assert(count($found)===1 &&$found[0]['permission']==='cos.tenant.manage'
        &&$found[0]['approval_policy']==='conditional'
        &&$found[0]['idempotency']==='required',
        'Missing canonical manager capability or gate for '.$id);
}
echo "Product Management E5 reservation architecture contract passed.\n";
