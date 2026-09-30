<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Contract\PropertyReferencePort;
use Domains\Property\Automation\Event\PropertyEventType;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$reflection = new ReflectionClass(PropertyReferencePort::class);
foreach (['getPropertyReference','getInventorySnapshot','findAvailableInventory','getPropertyPresentation'] as $method) {
    $assert($reflection->hasMethod($method), 'PropertyReferencePort missing cross-domain operation: ' . $method);
}
foreach ([
    PropertyEventType::PROPERTY_CREATED,
    PropertyEventType::PROPERTY_STRUCTURE_CHANGED,
    PropertyEventType::PROPERTY_LIFECYCLE_CHANGED,
    PropertyEventType::PROPERTY_RELATION_CHANGED,
    PropertyEventType::INVENTORY_CREATED,
    PropertyEventType::INVENTORY_PRICE_CHANGED,
    PropertyEventType::INVENTORY_STATUS_CHANGED,
    PropertyEventType::INVENTORY_AVAILABLE,
    PropertyEventType::LISTING_CREATED,
    PropertyEventType::LISTING_PUBLISHED,
    PropertyEventType::LISTING_HIDDEN,
] as $event) {
    $assert(in_array($event, PropertyEventType::values(), true), 'Canonical V0.7 event is not owned by Property: ' . $event);
}

$adapter = file_get_contents($root . '/app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPropertyReferencePort.php') ?: '';
$assert(!str_contains($adapter, 'tn_properties'), 'The canonical cross-domain Property port must never fall back to the legacy tn_properties table.');
$assert(str_contains($adapter, 'tn_property_inventory_items') && str_contains($adapter, 'tn_property_listings'), 'PropertyReferencePort must compose Registry, Inventory and Listing snapshots.');

echo "Property V0.7 history and cross-domain contracts: OK\n";
