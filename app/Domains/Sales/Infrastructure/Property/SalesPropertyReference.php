<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Property;

use Domains\Property\Contract\PropertyReferencePort;

final readonly class SalesPropertyReference
{
    public function __construct(
        private PropertyReferencePort $properties,
        private string $organizationId,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function property(int $legacyPropertyId, bool $activeOnly = false): ?array
    {
        if ($legacyPropertyId <= 0) return null;
        $snapshot = $this->properties->getPropertyPresentation($this->organizationId, $legacyPropertyId);
        if ($snapshot === null) return null;

        $property = is_array($snapshot['property'] ?? null) ? $snapshot['property'] : [];
        $inventory = is_array($snapshot['inventory'] ?? null) ? $snapshot['inventory'] : [];
        $listing = is_array($snapshot['listing'] ?? null) ? $snapshot['listing'] : [];
        $commercialStatus = trim((string) ($inventory['status'] ?? ''));
        if ($activeOnly && !in_array($commercialStatus, ['available', 'reserved', 'under_offer'], true)) {
            return null;
        }

        $publicId = trim((string) ($property['public_id'] ?? ''));
        if ($publicId === '') $publicId = (string) ($property['asset_id'] ?? '');
        $title = trim((string) ($listing['title'] ?? $property['legacy_title'] ?? ''));
        if ($title === '') $title = $publicId;
        $slug = trim((string) ($listing['slug'] ?? $property['legacy_slug'] ?? ''));
        $priceAmount = $inventory['price_amount'] ?? $listing['presentation_price_amount'] ?? null;
        $priceCurrency = $inventory['price_currency'] ?? $listing['presentation_price_currency'] ?? null;
        $area = $property['residential_total_area']
            ?? $property['commercial_total_area']
            ?? $property['gross_area']
            ?? $property['land_area']
            ?? null;

        return [
            'id' => $legacyPropertyId,
            'asset_id' => $property['asset_id'] ?? null,
            'inventory_id' => $inventory['inventory_id'] ?? null,
            'public_id' => $publicId,
            'title' => $title,
            'slug' => $slug !== '' ? $slug : null,
            'type_id' => isset($property['legacy_type_id']) ? (int) $property['legacy_type_id'] : null,
            'location_id' => isset($property['legacy_location_id']) ? (int) $property['legacy_location_id'] : null,
            'price_amount' => $priceAmount !== null ? (float) $priceAmount : null,
            'price_currency' => $priceCurrency !== null ? (string) $priceCurrency : null,
            'status' => $commercialStatus !== '' ? $commercialStatus : ($property['lifecycle'] ?? null),
            'area_total' => $area !== null ? (float) $area : null,
            'type_name' => $property['type_name'] ?? $property['type_code'] ?? null,
            'city' => $property['city'] ?? null,
            'cover_url' => $property['cover_url'] ?? null,
        ];
    }

    /** @return list<int> */
    public function searchLegacyPropertyIds(string $query, int $limit = 100): array
    {
        $ids = [];
        foreach ($this->properties->searchPropertyReferences($this->organizationId, $query, $limit) as $reference) {
            $id = (int) ($reference['legacy_property_id'] ?? 0);
            if ($id > 0) $ids[$id] = $id;
        }
        return array_values($ids);
    }
}
