<?php

declare(strict_types=1);

namespace App\Infrastructure\Experience\Search;

use App\Application\Experience\Search\Contract\PropertyEntitySearchInterface;
use App\Application\Experience\Search\EntitySearchHit;
use Domains\Property\Contract\PropertyReferencePort;

final readonly class PropertyEntitySearchAdapter implements PropertyEntitySearchInterface
{
    public function __construct(private PropertyReferencePort $properties)
    {
    }

    public function search(string $organizationId, string $query, int $limit = 10): array
    {
        $query = trim($query);
        $limit = max(1, min(50, $limit));

        if ($query === '') {
            return [];
        }

        $items = [];

        foreach ($this->properties->searchPropertyReferences($organizationId, $query, min($limit, 12)) as $reference) {
            $assetId = trim((string) ($reference['asset_id'] ?? ''));
            if ($assetId === '') {
                continue;
            }

            $presentation = $this->properties->getPropertyPresentation($organizationId, $assetId);
            $property = is_array($presentation['property'] ?? null) ? $presentation['property'] : [];
            $inventory = is_array($presentation['inventory'] ?? null) ? $presentation['inventory'] : [];
            $listing = is_array($presentation['listing'] ?? null) ? $presentation['listing'] : [];

            $title = trim((string) ($listing['title'] ?? ''));
            $address = trim((string) ($property['formatted_address'] ?? ''));
            $location = trim((string) ($property['location_name'] ?? ''));
            $type = trim((string) ($property['type_code'] ?? $property['kind'] ?? ''));
            $slug = trim((string) ($listing['slug'] ?? ''));

            $label = $title !== ''
                ? $title
                : ($address !== '' ? $address : ($location !== '' ? $location . ' · ' . $type : 'Property ' . $assetId));

            $items[] = new EntitySearchHit(
                id: 'property.asset.' . $assetId,
                label: $label,
                path: $slug !== '' ? '/property/show/' . rawurlencode($slug) : '/property/manage',
                entityType: 'property.asset',
                entityId: $assetId,
                subtitle: implode(' · ', array_values(array_filter([
                    $assetId,
                    $location,
                    $type,
                    trim((string) ($inventory['status'] ?? '')),
                ]))),
                score: 91.0,
            );
        }

        return array_slice($items, 0, $limit);
    }
}
