<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Application\DTO\ChangePropertyLifecycleCommand;
use Domains\Property\Application\DTO\PropertyCommandContext;
use Domains\Property\Application\DTO\ReclassifyPropertyAssetCommand;
use Domains\Property\Application\DTO\RegisterPropertyAssetCommand;
use Domains\Property\Application\DTO\RelocatePropertyAssetCommand;
use Domains\Property\Automation\Event\PropertyDomainEvents;
use Domains\Property\Automation\Event\PropertyEventType;
use Domains\Property\Bootstrap\PropertyDomainModule;
use Domains\Property\Model\PropertyAsset;
use Domains\Property\Model\PropertyLifecycle;
use Domains\Property\Model\PropertyLocation;
use Domains\Property\Model\PropertyType;
use Kernel\Event\EventMetadata;
use Kernel\Module\DomainModuleRegistry;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expectException = static function (callable $callback, string $exceptionClass, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $e) {
        $assert($e instanceof $exceptionClass, $message . ' Wrong exception: ' . $e::class);
        return;
    }

    throw new RuntimeException($message . ' No exception thrown.');
};

$context = new PropertyCommandContext('org-a', 'corr-property-001', 'USER', '42');
$type = PropertyType::fromReference(1, 'apartment');
$location = new PropertyLocation(
    'UA',
    'Львівська область',
    'Львів',
    'Шевченківський',
    'вул. Прикладна, 1',
    49.8397,
    24.0297,
    10,
);
$lifecycle = PropertyLifecycle::from(PropertyLifecycle::COMPLETED);
$asset = new PropertyAsset(
    'org-a',
    'TN-2026-1001',
    $type,
    $location,
    $lifecycle,
    84.5,
    55.0,
    null,
    3.0,
    4,
    8,
    2024,
    1001,
);

$register = new RegisterPropertyAssetCommand($context, $asset);
$assert($register->asset === $asset, 'RegisterPropertyAssetCommand must carry the typed aggregate.');

$changeLifecycle = new ChangePropertyLifecycleCommand(
    $context,
    $asset->assetId,
    PropertyLifecycle::from(PropertyLifecycle::DAMAGED),
);
$assert($changeLifecycle->assetId === $asset->assetId, 'Lifecycle command lost asset identity.');

$relocate = new RelocatePropertyAssetCommand(
    $context,
    $asset->assetId,
    new PropertyLocation('UA', 'Львівська область', 'Брюховичі'),
);
$assert($relocate->location->city === 'Брюховичі', 'Relocation command lost the typed location.');

$reclassify = new ReclassifyPropertyAssetCommand(
    $context,
    $asset->assetId,
    PropertyType::fromReference(2, 'house'),
);
$assert($reclassify->type->code === 'house', 'Reclassification command lost the typed PropertyType.');

$expectException(
    static fn () => new RegisterPropertyAssetCommand(
        new PropertyCommandContext('org-b', 'corr-property-002', 'USER', '43'),
        $asset,
    ),
    InvalidArgumentException::class,
    'RegisterPropertyAssetCommand must reject a cross-tenant aggregate.',
);

$expectException(
    static fn () => new ChangePropertyLifecycleCommand(
        $context,
        '??',
        PropertyLifecycle::unknown(),
    ),
    InvalidArgumentException::class,
    'Property commands must reject malformed canonical asset ids.',
);

$metadata = new EventMetadata(
    $context->correlationId,
    null,
    $context->actorType,
    $context->actorId,
);

$registered = PropertyDomainEvents::assetRegistered($asset, $metadata);
$assert($registered->organizationId === 'org-a', 'Property event lost tenant identity.');
$assert($registered->type === PropertyEventType::ASSET_REGISTERED, 'Registered event type drifted.');
$assert($registered->aggregateType === 'property_asset', 'Property event aggregate type drifted.');
$assert($registered->aggregateId === $asset->assetId, 'Property event lost aggregate identity.');
$assert(($registered->payload['type']['code'] ?? null) === 'apartment', 'Registered event lost PropertyType.');
$assert(($registered->payload['location']['city'] ?? null) === 'Львів', 'Registered event lost PropertyLocation.');
$assert(($registered->payload['lifecycle'] ?? null) === PropertyLifecycle::COMPLETED, 'Registered event lost lifecycle.');
$assert(($registered->payload['physical']['total_area'] ?? null) === 84.5, 'Registered event lost physical facts.');
foreach (['price', 'price_amount', 'status', 'visibility', 'sale_priority', 'reserved'] as $commercialField) {
    $assert(!array_key_exists($commercialField, $registered->payload), 'Property event leaked commercial field: ' . $commercialField);
}

$lifecycleChanged = PropertyDomainEvents::lifecycleChanged(
    $asset->organizationId,
    $asset->assetId,
    $asset->lifecycle,
    PropertyLifecycle::from(PropertyLifecycle::DAMAGED),
    $metadata,
);
$assert(($lifecycleChanged->payload['previous_lifecycle'] ?? null) === PropertyLifecycle::COMPLETED, 'Lifecycle event lost previous value.');
$assert(($lifecycleChanged->payload['lifecycle'] ?? null) === PropertyLifecycle::DAMAGED, 'Lifecycle event lost next value.');

$typeChanged = PropertyDomainEvents::typeChanged(
    $asset->organizationId,
    $asset->assetId,
    $asset->type,
    PropertyType::fromReference(2, 'house'),
    $metadata,
);
$assert(($typeChanged->payload['previous_type']['code'] ?? null) === 'apartment', 'Type event lost previous type.');
$assert(($typeChanged->payload['type']['code'] ?? null) === 'house', 'Type event lost next type.');

$locationChanged = PropertyDomainEvents::locationChanged(
    $asset->organizationId,
    $asset->assetId,
    $asset->location,
    $relocate->location,
    $metadata,
);
$assert(($locationChanged->payload['previous_location']['city'] ?? null) === 'Львів', 'Location event lost previous location.');
$assert(($locationChanged->payload['location']['city'] ?? null) === 'Брюховичі', 'Location event lost next location.');

$module = new PropertyDomainModule();
$registry = new DomainModuleRegistry([$module]);
foreach (PropertyEventType::values() as $eventType) {
    $assert($registry->ownerOfEvent($eventType) === 'property', 'Kernel lost Property event ownership: ' . $eventType);
}

$ruleContext = $module->ruleContextProvider()->contextFor($registered);
$assert(($ruleContext['organization_id'] ?? null) === 'org-a', 'Property rule context lost tenant identity.');
$assert(($ruleContext['asset_id'] ?? null) === $asset->assetId, 'Property rule context lost asset identity.');
$assert(($ruleContext['event_type'] ?? null) === PropertyEventType::ASSET_REGISTERED, 'Property rule context lost event type.');
$assert(($ruleContext['property']['lifecycle'] ?? null) === PropertyLifecycle::COMPLETED, 'Property rule context lost event payload.');

$expectException(
    static fn () => $module->ruleContextProvider()->contextFor(new Kernel\Event\DomainEvent(
        'evt-1',
        'org-a',
        'sales.deal.won',
        'deal',
        'deal-1',
        [],
        $metadata,
        new DateTimeImmutable(),
    )),
    InvalidArgumentException::class,
    'Property rule context must reject foreign domain events.',
);

echo "Property V0.2.4 commands and domain events: OK\n";
