<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$routes=$read('symfony/config/routes.yaml');
$controller=$read('symfony/src/Http/Api/V1/Controller/ShopifyWebhookController.php');
$store=$read('app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyObservationStore.php');
$assert=static function(bool $yes,string $message):void {
    if (!$yes) throw new RuntimeException($message);
};
$assert(str_contains($routes,'cos_product_management_shopify_webhook')
    && str_contains($routes,'/api/v1/integrations/shopify/webhook'),'Webhook route missing');
$extractor=$read('app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyOrderWebhookEvidence.php');
$verify=strpos($extractor,'ShopifyWebhookVerifier::verify(');
$extract=strpos($controller,'ShopifyOrderWebhookEvidence::extractVerified(');
$persist=strpos($controller,'recordVerifiedDelivery(');
$assert($verify!==false && $extract!==false && $persist!==false && $extract<$persist
    && $verify<strpos($extractor,'json_decode('),
    'Webhook must authenticate raw body before extraction and persistence.');
$assert(str_contains($controller,"'product_management'") && str_contains($controller,'$this->modules->isEnabled('),
    'Disabled tenant must be rejected.');
$assert(str_contains($store,'uniqueConnectionForShop') && str_contains($store,'count($rows)!==1'),
    'Ambiguous shop-to-tenant routing must fail closed.');
$assert(!str_contains($controller,'ProductManagementService') && !str_contains($controller,'ShopifyCatalogPullService'),
    'Webhook cannot directly mutate product or initiate sync.');
echo "Product Management Shopify webhook boundary passed.\n";
