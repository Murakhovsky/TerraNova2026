<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$command=(string)file_get_contents($root.'/symfony/src/Command/ProductManagementShopifyOperatorCommand.php');
$read=(string)file_get_contents($root.'/app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyCatalogPullService.php');
$check=static function(bool $ok,string $why):void {if (!$ok) throw new RuntimeException($why);};
foreach (['COS_SHOPIFY_OPERATOR_ENABLED','PROVISION_READ_ONLY','PULL_READ_ONLY',
    "getOption('organization')","getOption('connection')",
    "getOption('pages')",'$this->modules->isEnabled(',
    '$this->credentials->accessToken(', '$this->puller->pull('] as $needle) {
    $check(str_contains($command,$needle),'Shopify operator boundary missing: '.$needle);
}
$check(str_contains($command,"'pages',null,InputOption::VALUE_REQUIRED")
    &&str_contains($command,'$pages>20') &&str_contains($read,'$maxPages>20'),
    'Shopify pull must remain bounded.');
$check(!str_contains($command,'SHOPIFY_CLIENT_SECRET')
    &&!str_contains($command,'->updateProduct(')
    &&!str_contains($command,'->recordMovement(')
    &&!str_contains($command,'->createProduct('),
    'Operator bypasses read-only scope.');
$store=(string)file_get_contents($root.'/app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyObservationStore.php');
$check(str_contains($command,"'candidates'")
    && str_contains($command,"'limit',null,InputOption::VALUE_REQUIRED")
    && str_contains($command,"'status'=>'SNAPSHOT_CANDIDATES'")
    && str_contains($store,'function candidates(')
    && str_contains($store,'WHERE organization_id=? AND connection_id=?')
    && str_contains($store,'stage_preconditions_met')
    && !str_contains($command,'->createProduct('),
    'Shopify candidate operator must stay bounded and read-only.');
echo "Product Management supervised Shopify operator contract passed.\n";
