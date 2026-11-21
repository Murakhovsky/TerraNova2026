<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$store=$read('app/Domains/ProductManagement/Infrastructure/Persistence/MySqlProductManagementStore.php');
$price=$read('app/Domains/ProductManagement/Application/Service/PricingService.php');
$apply=$read('app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyImportApplyService.php');
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$guard='assertShopifyLocalOwnership(';
$assert(substr_count($store,$guard)>=8,'Shopify owner guard absent from native Product/Variant/Stock writers.');
foreach(['public function createVariant','public function updateVariant',
    'public function archiveVariant','public function updateProduct','public function archiveProduct',
    'public function postMovement','public function transferStock'] as $method) {
    $i=strpos($store,$method);
    $next=strpos($store,'public function ',$i+16);
    $chunk=substr($store,$i,($next===false?strlen($store):$next)-$i);
    $assert($i!==false&&str_contains($chunk,$guard),'Shopify owner guard missing on '.$method);
}
$assert(str_contains($store,"c.mode='shopify_authoritative'")
    &&str_contains($price,"c.mode='shopify_authoritative'")
    &&str_contains($price,'Shopify-authoritative Price requires controlled owner cutover.'),
    'Native Price and Stock cannot rely on a caller-supplied authority claim.');
$assert(strpos($apply,'$this->pricing->set(')<strpos($apply,'$this->observations->map('),
    'Approved importer must finish native Price initialization before Product owner mapping.');
$assert(!str_contains($apply,'postMovement('),'Shopify approved import may not post native Stock.');
echo "Product Management Shopify authoritative ownership fences passed.\n";
