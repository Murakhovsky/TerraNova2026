<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$get=static fn(string $f):string=>(string)file_get_contents($root.'/'.$f);
$pull=$get('app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyCatalogPullService.php');
$preview=$get('app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyImportPreviewService.php');
$module=$get('app/Domains/ProductManagement/module.php');
$store=$get('app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyObservationStore.php');
$cli=$get('symfony/src/Command/ProductManagementShopifyOperatorCommand.php');
$assert=static function(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);};
$assert(str_contains($pull,'shop { currencyCode }')
    &&str_contains($pull,'recordShopCurrency('),'Shopify read must verify shop currency.');
$assert(str_contains($pull,'observeProduct($org,$conn,$gid,$remote,true)')
    &&str_contains($store,'is_complete=VALUES(is_complete)'),
    'Only fully paginated observed Shopify product can be staged.');
foreach(['$this->modules->isEnabled(','productSnapshot(','hash_equals($expectedHash',
         'pm_shopify_import_previews','Canonical SKU already belongs',
         'independent_approved_canonical_import'] as $needle) {
    $assert(str_contains($preview,$needle),'Missing fail-closed preview boundary: '.$needle);
}
$assert(!str_contains($preview,'createProduct(')
    &&!str_contains($preview,'createVariant(')
    &&!str_contains($preview,'->set(')
    &&!str_contains($preview,'->map('),
    'Shopify preview must never be a canonical catalog writer.');
$assert(str_contains($cli,'STAGE_NO_WRITES') &&str_contains($cli,"getOption('expected-hash')"),
    'Shopify staging requires explicit human operator intent and source fingerprint.');
$assert(str_contains($module,'20261119_000159_product_management_shopify_import_preview.sql'),
    'Shopify preview migration must be in module manifest.');
echo "Product Management Shopify immutable preview architecture passed.\n";
