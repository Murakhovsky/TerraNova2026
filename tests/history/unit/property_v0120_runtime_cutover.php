<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Automation\Event\PropertyDomainEvents;
use Domains\Property\Automation\Event\PropertyEventType;
use Domains\Property\Model\PropertyAsset;
use Domains\Property\Model\PropertyLifecycle;
use Domains\Property\Model\PropertyLocation;
use Domains\Property\Model\PropertyType;
use Kernel\Event\EventMetadata;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$before = new PropertyAsset(
    'org-v012',
    'PROP-V012-001',
    new PropertyType('apartment', 1),
    new PropertyLocation('UA', 'Львівська область', 'Львів', null, 'вул. Тестова, 1'),
    PropertyLifecycle::from(PropertyLifecycle::COMPLETED),
    64.2,
    40.0,
    null,
    2.0,
    3,
    5,
    2024,
);
$after = new PropertyAsset(
    'org-v012',
    'PROP-V012-001',
    new PropertyType('house', 2),
    new PropertyLocation('UA', 'Львівська область', 'Брюховичі', null, 'вул. Тестова, 2'),
    PropertyLifecycle::from(PropertyLifecycle::DAMAGED),
    120.0,
    80.0,
    6.0,
    4.0,
    1,
    2,
    2024,
);
$metadata = new EventMetadata('corr-v012', null, 'USER', '42');
$events = PropertyDomainEvents::changes($before, $after, $metadata);
$types = array_map(static fn ($event): string => $event->type, $events);

foreach ([
    PropertyEventType::TYPE_CHANGED,
    PropertyEventType::LOCATION_CHANGED,
    PropertyEventType::LIFECYCLE_CHANGED,
    PropertyEventType::PROPERTY_STRUCTURE_CHANGED,
] as $expected) {
    $assert(in_array($expected, $types, true), 'V0.12 mutation did not emit expected Property event: ' . $expected);
}
foreach ($events as $event) {
    $assert($event->organizationId === 'org-v012', 'V0.12 Property event lost tenant identity.');
    $assert($event->aggregateId === 'PROP-V012-001', 'V0.12 Property event lost canonical asset identity.');
}

$registered = PropertyDomainEvents::assetRegistered($before, $metadata);
$assert(($registered->payload['kind'] ?? null) === 'unit', 'Asset registration event must expose canonical asset kind.');
$assert(!array_key_exists('price_amount', $registered->payload), 'Physical Property event must not leak Inventory price.');

$projectionContract = new ReflectionClass(Domains\Property\Application\Contract\PropertyProjectionInterface::class);
foreach (['sync', 'syncOperationalMetadata', 'recordActivity'] as $method) {
    $assert($projectionContract->hasMethod($method), 'Property projection contract missing method: ' . $method);
}

echo "Property V0.12 canonical runtime regression: OK\n";
