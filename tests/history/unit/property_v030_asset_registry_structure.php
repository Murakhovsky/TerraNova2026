<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Property\Model\BuildingSpec;
use Domains\Property\Model\CommercialSpec;
use Domains\Property\Model\GeoBoundary;
use Domains\Property\Model\GeoPoint;
use Domains\Property\Model\LandSpec;
use Domains\Property\Model\LocationNode;
use Domains\Property\Model\LocationNodeType;
use Domains\Property\Model\PropertyAddress;
use Domains\Property\Model\PropertyAsset;
use Domains\Property\Model\PropertyAssetKind;
use Domains\Property\Model\PropertyAssetRelation;
use Domains\Property\Model\PropertyAssetRelationType;
use Domains\Property\Model\PropertyLifecycle;
use Domains\Property\Model\PropertyLocation;
use Domains\Property\Model\PropertyStructureGraph;
use Domains\Property\Model\PropertyType;
use Domains\Property\Model\ResidentialSpec;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$location = new PropertyLocation('UA', 'Львівська область', 'Львів', latitude: 49.8397, longitude: 24.0297);
$lifecycle = PropertyLifecycle::from(PropertyLifecycle::COMPLETED);

$asset = static function (string $id, string $kind, string $type) use ($location, $lifecycle): PropertyAsset {
    return new PropertyAsset(
        'org-a',
        $id,
        new PropertyType($type),
        $location,
        $lifecycle,
        kind: PropertyAssetKind::from($kind),
    );
};

$development = $asset('TN-LH', PropertyAssetKind::DEVELOPMENT, 'residential_complex');
$building = $asset('TN-LH-B2', PropertyAssetKind::BUILDING, 'building');
$entrance = $asset('TN-LH-B2-E1', PropertyAssetKind::ENTRANCE, 'entrance');
$floor = $asset('TN-LH-B2-E1-F2', PropertyAssetKind::FLOOR, 'floor');
$apartment = $asset('TN-023', PropertyAssetKind::UNIT, 'apartment');

$contains = PropertyAssetRelationType::from(PropertyAssetRelationType::CONTAINS);
$relations = [
    new PropertyAssetRelation('org-a', $development->assetId, $building->assetId, $contains, 1),
    new PropertyAssetRelation('org-a', $building->assetId, $entrance->assetId, $contains, 1),
    new PropertyAssetRelation('org-a', $entrance->assetId, $floor->assetId, $contains, 2),
    new PropertyAssetRelation('org-a', $floor->assetId, $apartment->assetId, $contains, 23),
];

$graph = new PropertyStructureGraph('org-a', [$development, $building, $entrance, $floor, $apartment], $relations);
$path = $graph->path('TN-LH', 'TN-023');
$assert(array_map(static fn (PropertyAsset $item): string => $item->assetId, $path) === [
    'TN-LH', 'TN-LH-B2', 'TN-LH-B2-E1', 'TN-LH-B2-E1-F2', 'TN-023',
], 'Property V0.3 must resolve an arbitrary structural path to a Unit.');
$assert($apartment->kind->value === PropertyAssetKind::UNIT, 'Apartment must be modeled as UNIT kind.');
$assert($apartment->type->code === 'apartment', 'Apartment specialization belongs to PropertyType.');

$plot = $asset('TN-PLOT-01', PropertyAssetKind::LAND_PLOT, 'land_plot');
$house = $asset('TN-HOUSE-01', PropertyAssetKind::HOUSE, 'house');
$plotGraph = new PropertyStructureGraph('org-a', [$plot, $house], [
    new PropertyAssetRelation('org-a', $plot->assetId, $house->assetId, $contains),
]);
$assert(count($plotGraph->path($plot->assetId, $house->assetId)) === 2, 'LandPlot -> House must not require fake hierarchy levels.');

$legacyApartment = new PropertyAsset('org-a', 'TN-LEGACY-01', new PropertyType('apartment'), $location, $lifecycle);
$assert($legacyApartment->kind->value === PropertyAssetKind::UNIT, 'V0.2 aggregate construction must remain backward compatible through kind inference.');

$country = new LocationNode('LOC-UA', LocationNodeType::from(LocationNodeType::COUNTRY), 'Україна', 'ua', countryCode: 'UA');
$region = new LocationNode('LOC-UA-46', LocationNodeType::from(LocationNodeType::REGION), 'Львівська область', 'ua/lviv-region', $country->nodeId, 'UA');
$city = new LocationNode('LOC-UA-LVIV', LocationNodeType::from(LocationNodeType::CITY), 'Львів', 'ua/lviv-region/lviv', $region->nodeId, 'UA');
$street = new LocationNode('LOC-UA-LVIV-LISOVA', LocationNodeType::from(LocationNodeType::STREET), 'Лісова', 'ua/lviv-region/lviv/lisova', $city->nodeId, 'UA');
$address = new PropertyAddress($city->nodeId, $street->nodeId, '12');
$point = new GeoPoint(49.8397, 24.0297);
$boundary = new GeoBoundary([new GeoPoint(49.83, 24.02), new GeoPoint(49.84, 24.02), new GeoPoint(49.84, 24.03)]);
$assert($address->streetNodeId === $street->nodeId && $point->latitude === 49.8397 && count($boundary->points) === 3, 'Normalized location primitives drifted.');

$residential = new ResidentialSpec(64.2, 41.0, 2.0, 1, 1);
$land = new LandSpec(800.0, 300.0);
$commercial = new CommercialSpec(120.0, 105.0, 3.4, 2);
$buildingSpec = new BuildingSpec(5200.0, 4, 2026);
$assert($residential->totalArea === 64.2 && $land->landArea === 800.0 && $commercial->usableArea === 105.0 && $buildingSpec->floors === 4, 'Typed physical specs drifted.');

$snapshot = $apartment->toArray();
$assert(!array_key_exists('price', $snapshot), 'PropertyAsset must not own commercial price state.');
$assert(!array_key_exists('buyer', $snapshot), 'PropertyAsset must not own CRM buyer state.');

echo "Property V0.3 asset registry and structure: OK\n";
