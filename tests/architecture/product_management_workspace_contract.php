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
echo "Product Management E7/P0 catalog workspace architecture passed.\n";
