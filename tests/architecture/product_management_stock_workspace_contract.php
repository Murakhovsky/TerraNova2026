<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$controller=$read('symfony/src/Web/ProductManagement/ProductInventoryController.php');
$twig=$read('symfony/templates/experience/product_management/stock.html.twig');
$module=$read('app/Domains/ProductManagement/module.php');
$provider=$read('symfony/src/Web/Experience/Extension/Provider/ProductManagementWebProvider.php');
$routes=$read('symfony/config/routes.yaml');
$workflow=$read('.github/workflows/runtime.yml');
$store=$read('app/Domains/ProductManagement/Infrastructure/Persistence/MySqlProductManagementStore.php');
$adapter=$read('symfony/src/Infrastructure/Capability/ProductLocationSearchAdapter.php');
$assert=static function(bool $good,string $msg):void{
    if(!$good)throw new RuntimeException('PM inventory Workspace: '.$msg);
};
foreach(['CanonicalCapabilityGateway','SessionCsrfValidator','TenantPermissions::MANAGE',
    'COS_SHOPIFY_OPERATOR_ENABLED','ProductManagementService'] as $marker){
    if(in_array($marker,['COS_SHOPIFY_OPERATOR_ENABLED','ProductManagementService'],true))
        $assert(!str_contains($controller,$marker),'Inventory route may not bypass Gateway: '.$marker);
    else $assert(str_contains($controller,$marker),'Missing typed human gate: '.$marker);
}
foreach(['location.search','stock.balance.get','stock.movement.search','stock.receive',
    'stock.issue','stock.transfer','location.create',"'trusted_read'","'human_ui'"] as $marker){
    $assert(str_contains($controller,$marker),'Missing live canonical stock operation: '.$marker);
}
foreach(['PDO','->prepare(', 'ShopifyReadClient','source_ref','idempotency_key'] as $key){
    if(in_array($key,['PDO','->prepare(', 'ShopifyReadClient'],true))
        $assert(!str_contains($controller,$key),'Inventory gained unauthorized persistence path');
    else $assert(str_contains($controller,$key),'Missing stock movement evidence: '.$key);
}
$assert(str_contains($adapter,"'query:product_management.location.search'")
    &&str_contains($store,'WHERE organization_id=:org')
    &&str_contains($module,"'id'=>'product_management.location.search'"),
    'Stock location discovery must be tenant-scoped and one canonical binding.');
$assert(str_contains($provider,"'/product-management/stock'")
    &&str_contains($routes,'cos_product_management_stock:')
    &&str_contains($twig,'action="/product-management/stock/post"')
    &&substr_count($twig,'name="csrf_token"')>=2,
    'Inventory Workspace routes or guarded forms not registered.');
$assert(str_contains($workflow,'php tests/architecture/product_management_stock_workspace_contract.php'),
    'Runtime architecture gate missing.');
foreach(['stock.reservation.search','reservation_readback_uncertain','confirm_release'] as $required)
    $assert(str_contains($controller,$required),'Reservation Workspace missing: '.$required);
$assert(str_contains($routes,'cos_product_management_stock_reservation:')&&str_contains($twig,'name="reservation_id"'),
    'Reservation requires guarded canonical route and identity.');
$assert(str_contains($controller,'stock.count.get')&&str_contains($controller,'$this->countPlans->propose(')
    &&str_contains($routes,'cos_product_management_stock_count_propose:')&&str_contains($twig,'name="expected_revision"'),
    'Count proposal must be revision-pinned and read through canonical query.');
$assert(!str_contains($controller,'applyApprovedStockCount'),'Workspace must not invoke approved stock writer.');
$assert(str_contains($controller,'stock.count.session.candidates')&&str_contains($controller,'stock.count.session.get')
    &&str_contains($controller,'$this->sessionPlans->propose(')&&str_contains($twig,'id="stock-session"')
    &&str_contains($routes,'cos_product_management_stock_count_session_propose:'),'Canonical mass inventory session workspace missing.');
echo "Product Management inventory Workspace architecture passed.\n";
