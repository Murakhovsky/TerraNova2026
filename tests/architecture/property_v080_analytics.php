<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$propertyContract = $read('app/Domains/Property/Application/Contract/PropertyAnalyticsReadModelInterface.php');
foreach (['summary','inventorySegments','stock','changes'] as $method) {
    $assert(str_contains($propertyContract, 'function ' . $method), 'Property V0.8 analytics contract missing: ' . $method);
}

$propertyReadModel = $read('app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPropertyAnalyticsReadModel.php');
foreach (['tn_property_assets','tn_property_inventory_items','tn_property_inventory_price_history','tn_property_inventory_status_history','tn_property_publications'] as $table) {
    $assert(str_contains($propertyReadModel, $table), 'Property V0.8 analytics read model missing canonical source: ' . $table);
}
$assert(!str_contains($propertyReadModel, 'tn_properties'), 'Property V0.8 analytics must not fall back to legacy tn_properties.');
$assert(!str_contains($propertyReadModel, 'tn_client_cases'), 'Property Domain must not read Sales tables for analytics.');

$salesDemand = $read('app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlSalesDemandReadModel.php');
foreach (['tn_client_cases','tn_client_case_property_matches'] as $table) {
    $assert(str_contains($salesDemand, $table), 'Sales demand projection missing owned table: ' . $table);
}
foreach (['tn_property_assets','tn_property_inventory_items','tn_property_listings'] as $forbidden) {
    $assert(!str_contains($salesDemand, $forbidden), 'Sales demand projection crossed into Property storage: ' . $forbidden);
}

$market = $read('app/Infrastructure/Platform/Analytics/PropertyMarketAnalyticsService.php');
foreach (['PropertyAnalyticsReadModelInterface','SalesDemandReadModelInterface','demand_supply_ratio','explicit_property_matches','demand_coverage'] as $needle) {
    $assert(str_contains($market, $needle), 'Property V0.8 market analytics composition missing: ' . $needle);
}

$module = require $root . '/app/Domains/Property/module.php';
$assert(($module['version'] ?? null) === '0.8.0', 'Property module must be V0.8.0.');
$assert(in_array('property.analytics', $module['contributions']['capabilities'] ?? [], true), 'Property V0.8 analytics capability missing.');

echo "Property V0.8 analytics architecture: OK\n";
