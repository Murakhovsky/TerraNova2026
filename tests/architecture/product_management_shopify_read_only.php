<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$pull=(string)file_get_contents($root.'/app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyCatalogPullService.php');
$webhook=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/ShopifyWebhookController.php');
$assert=static function(bool $ok,string $msg):void { if (!$ok) throw new RuntimeException($msg); };
$assert(str_contains($pull,'observeProduct(')&&str_contains($pull,'observeStock('),'Shopify observations missing.');
$assert(str_contains($pull,'$this->modules->isEnabled(')
    && str_contains($pull,"'product_management'"),'Product pull must require active tenant module.');
$assert(str_contains($pull,'Shopify product observed without approved COS identity mapping.'),
    'Unknown remote identity must be quarantined.');
$store=(string)file_get_contents($root.'/app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyObservationStore.php');
$assert(str_contains($store,'FOR UPDATE') && str_contains($store,'sync_run_id')
    && str_contains($store,'Stale Shopify sync cannot release another run.'),
    'Shopify sync must use tenant-scoped MySQL run fencing.');
$assert(!str_contains($pull,'$this->products->createProduct(')
    && !str_contains($pull,'$this->products->createVariant(')
    && !str_contains($pull,'$this->observations->map('),
    'Read-only sync must never mutate canonical Product or implicitly approve mappings.');
$assert(!str_contains($webhook,'ProductManagementService') && !str_contains($webhook,'ShopifyCatalogPullService'),
    'Incoming webhook cannot directly start a sync or create Product.');
echo "Shopify observation-only Product Management architecture passed.\n";
