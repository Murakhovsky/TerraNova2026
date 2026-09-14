<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use DomainException;
use Domains\Property\Model\PropertyAsset;
use Domains\Property\Model\PropertyLifecycle;
use Domains\Property\Model\PropertyLocation;
use Domains\Property\Model\PropertyType;
use InvalidArgumentException;

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
    'TN-2026-0001',
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

$assert($asset->organizationId === 'org-a', 'PropertyAsset must retain tenant identity.');
$assert($asset->type->code === 'apartment', 'PropertyAsset must use PropertyType.');
$assert($asset->location->hasCoordinates(), 'PropertyLocation must expose valid coordinate state.');
$assert($asset->lifecycle->value === PropertyLifecycle::COMPLETED, 'PropertyAsset lifecycle drifted.');
$assert(($asset->toArray()['physical']['total_area'] ?? null) === 84.5, 'PropertyAsset physical snapshot drifted.');

$house = $asset->reclassify(PropertyType::fromReference(2, 'house'));
$assert($house !== $asset && $house->type->code === 'house', 'PropertyAsset reclassification must be immutable.');
$assert($asset->type->code === 'apartment', 'PropertyAsset reclassification mutated the original aggregate.');

$damaged = $asset->changeLifecycle(PropertyLifecycle::from(PropertyLifecycle::DAMAGED));
$assert($damaged->lifecycle->value === PropertyLifecycle::DAMAGED, 'PropertyAsset lifecycle transition failed.');

$demolished = $asset->changeLifecycle(PropertyLifecycle::from(PropertyLifecycle::DEMOLISHED));
$expectException(
    static fn () => $demolished->changeLifecycle(PropertyLifecycle::from(PropertyLifecycle::COMPLETED)),
    DomainException::class,
    'Demolished PropertyAsset must be terminal.',
);

$expectException(
    static fn () => PropertyLifecycle::from('sold'),
    InvalidArgumentException::class,
    'Commercial Sales status must not be accepted as physical PropertyLifecycle.',
);
$expectException(
    static fn () => PropertyLifecycle::from('reserved'),
    InvalidArgumentException::class,
    'Inventory reservation must not be accepted as physical PropertyLifecycle.',
);
$expectException(
    static fn () => new PropertyLocation('UA', 'Львівська область', 'Львів', latitude: 49.8),
    InvalidArgumentException::class,
    'PropertyLocation must reject half-defined coordinates.',
);
$expectException(
    static fn () => new PropertyAsset(
        'org-a',
        'TN-2026-0002',
        $type,
        $location,
        $lifecycle,
        50.0,
        60.0,
    ),
    InvalidArgumentException::class,
    'PropertyAsset living area cannot exceed total area.',
);

echo "Property V0.2.3 core domain model: OK\n";
