<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$controller=$read('symfony/src/Web/ProductManagement/ProductManagementPageController.php');
$twig=$read('symfony/templates/experience/product_management/workspace.html.twig');
$provider=$read('symfony/src/Web/Experience/Extension/Provider/ProductManagementWebProvider.php');
$module=$read('app/Domains/ProductManagement/module.php');
$services=$read('symfony/config/services.yaml');
$routes=$read('symfony/config/routes.yaml');
$workflow=$read('.github/workflows/runtime.yml');
$assert=static function(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('PM Workspace architecture: '.$message);
};
foreach([
    'CanonicalCapabilityGateway', 'SessionCsrfValidator', 'TenantContextProviderInterface',
    'ActiveModuleResolver', 'TenantPermissions::MANAGE',
    '$this->modules->isEnabled(', '$this->csrf->isValid($request)',
    "'human_ui'", "'trusted_read'", 'CorrelationId::generate()',
    'product_management.', 'readProduct(', 'idempotency_key',
] as $required) $assert(str_contains($controller,$required),'Missing canonical guard: '.$required);
foreach(['new PDO','->prepare(', 'ProductManagementService','ShopifyReadClient',
    "'ai'", "'cron'", 'QueryBusInterface', 'CommandBusInterface',
] as $forbidden) $assert(!str_contains($controller,$forbidden),'Unapproved side path: '.$forbidden);
foreach([
    'product.search','product.get','category.search','product.create','product.update',
    'variant.create','translation.upsert','category.assign','price.base.set'
] as $capability) $assert(str_contains($controller,$capability),'Missing Product capability: '.$capability);
$assert(str_contains($module,"'web.navigation' => ['productManagementNavigationContributor']"),
    'Tenant-gated navigation extension absent.');
$assert(str_contains($provider,"'productManagementNavigationContributor'") &&
    str_contains($services,'productManagementNavigationContributor:') &&
    str_contains($services,'App\\Web\\ProductManagement\\ProductManagementPageController:'),
    'Workspace provider/controller not registered.');
$assert(str_contains($twig,"{% extends 'experience/workspace_shell.html.twig' %}") &&
    substr_count($twig,'name="csrf_token"')>=5 &&
    substr_count($twig,'name="idempotency_key"')>=5 &&
    str_contains($twig,'mutationKey|slice(0,24)'),
    'Workspace forms must be idempotent and CSRF-protected.');
foreach([
    'cos_product_management_index:', 'cos_product_management_create:',
    'cos_product_management_product:', 'cos_product_management_categories:',
    'cos_product_management_product_price:',
] as $name) $assert(str_contains($routes,$name),'Missing explicit route: '.$name);
$assert(str_contains($workflow,'php tests/architecture/product_management_workspace_contract.php'),
    'PM Workspace architecture gate must run in CI.');
foreach(['product.archive','variant.update','variant.archive','findVariant(','confirm_archive','lifecycle_readback_uncertain'] as $required) {
    $assert(str_contains($controller,$required),'Missing lifecycle guard/readback: '.$required);
}
foreach(['cos_product_management_product_archive:','cos_product_management_variant_update:','cos_product_management_variant_archive:'] as $name) {
    $assert(str_contains($routes,$name),'Missing lifecycle route: '.$name);
}
$assert(substr_count($twig,'name="confirm_archive"')===3 && str_contains($twig,'name="expected_revision"'),
    'Archive confirmation and revision fences required.');
$store=$read('app/Domains/ProductManagement/Infrastructure/Persistence/MySqlProductManagementStore.php');
$assert(str_contains($store,"WHERE v.organization_id=? AND v.variant_id=? FOR UPDATE', [".'$organizationId,$variantId]'),
    'Stock posting must lock Product/Variant before checking lifecycle.');
$assert(str_contains($store,'(b.on_hand<>0 OR b.reserved<>0) LIMIT 1 FOR UPDATE'),
    'Product archive must use current locking balance read.');
$pricing=$read('app/Domains/ProductManagement/Application/Service/PricingService.php');
$assert(str_contains($pricing,'Base price revision conflict.')&&str_contains($pricing,'Active tenant Variant and Product required for pricing.'),
    'Pricing must fence revision and archived lifecycle at Domain writer.');
$assert(str_contains($controller,'price_readback_uncertain')&&str_contains($twig,'price.revision')&&str_contains($twig,'priceCurrency'),
    'Multi-currency pricing requires observed revision and independent receipt readback.');
$assert(str_contains($controller,'price.history.search')&&str_contains($twig,'id="price-history"')
    &&str_contains($pricing,'WHERE organization_id=:org AND variant_id=:variant AND currency=:currency'),
    'Price history requires canonical query, native tenant scope and Workspace projection.');
foreach (['price.list.create','price.list.search','price.list.entry.get','price.list.entry.set','list_price_readback_uncertain'] as $required)
    $assert(str_contains($controller,$required),'Missing price list workspace contract: '.$required);
$assert(str_contains($routes,'cos_product_management_price_list_entry_set:')&&str_contains($twig,'id="list-price"'),
    'Price list workspace requires canonical routes and forms.');
foreach (['price.list.history.search','list_lifecycle_readback_uncertain','confirm_archive'] as $required)
    $assert(str_contains($controller,$required),'Missing price list lifecycle workspace contract: '.$required);
$assert(str_contains($routes,'cos_product_management_price_list_change:')&&str_contains($twig,'listHistory.items'),
    'Price list lifecycle routes and history form required.');
foreach (['price.resolve','price.list.entry.remove','price.list.tier.search','price.list.tier.set','confirm_remove'] as $required)
    $assert(str_contains($controller,$required),'Advanced pricing Workspace missing: '.$required);
$assert(str_contains($twig,'name="min_quantity"')&&str_contains($twig,'name="quote_qty"'),
    'Quantity tier editing and deterministic quote preview required.');
$assert(str_contains($controller,'price.list.tier.history.search')&&str_contains($twig,'id="tier-history"')&&str_contains($pricing,'FROM pm_price_list_tier_history'),
    'Tier history requires canonical query and native immutable history.');
foreach(['attribute.define','attribute.category.bind','attribute.product.filter','attribute.product.set','variant.matrix.preview','variant.matrix.create'] as $required)
    $assert(str_contains($controller,$required),'Attribute Workspace missing: '.$required);
$assert(str_contains($twig,'product_attributes.html.twig')&&str_contains($routes,'cos_product_management_product_attributes:'),'Attribute Workspace routes missing.');
foreach(['analytics.summary.get','analytics.stock.search','analytics.movement.search','analytics.quality.search','analytics.reconciliation.get'] as $required)
    $assert(str_contains($controller,$required),'Dashboard query missing: '.$required);
$assert(str_contains($twig,'dashboard.html.twig')&&str_contains($routes,'cos_product_management_dashboard:'),'Dashboard route missing.');
echo "Product Management E7/P0 catalog workspace architecture passed.\n";
