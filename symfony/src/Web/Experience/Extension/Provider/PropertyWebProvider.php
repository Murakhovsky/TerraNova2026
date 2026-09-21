<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\SearchProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Search\SearchResultMatcher;
use App\Web\Experience\Shell\ShellCommandItem;
use Domains\Property\Contract\PropertyReferencePort;

final class PropertyWebProvider implements NavigationProviderInterface, SearchProviderInterface, CommandProviderInterface, WorkspaceProviderInterface
{
    public function __construct(
        private readonly SearchResultMatcher $matcher,
        private readonly PropertyReferencePort $properties,
    ) {
    }

    public function serviceId(): string
    {
        return 'propertyNavigationContributor';
    }

    public function navigation(WebExtensionContext $context): array
    {
        return [
            new NavigationContribution('properties', 'Properties', '/property/manage', 'RE', 40),
            new NavigationContribution('objects', 'Inventory', '/property/manage', priority: 10, parentKey: 'properties'),
            new NavigationContribution('listing', 'Listing', '/property/listing', priority: 20, parentKey: 'properties'),
            new NavigationContribution('locations', 'Locations', '/property/map', priority: 30, parentKey: 'properties'),
            new NavigationContribution('submissions', 'Moderation', '/property/submissions', priority: 40, parentKey: 'properties'),
            new NavigationContribution('spatial', '3D / Spatial', '/spatial/manage', priority: 50, parentKey: 'properties'),
            new NavigationContribution('catalog', 'Public Catalog', '/property/catalog', priority: 60, parentKey: 'properties'),
        ];
    }

    public function search(WebExtensionContext $context, string $query, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $navigation = $this->matcher->match([
            new SearchResult('property.search.inventory', 'Property Inventory', '/property/manage', 'workspace', 'Properties'),
            new SearchResult('property.search.listing', 'Property Listing', '/property/listing', 'workspace', 'Listings'),
            new SearchResult('property.search.locations', 'Property Locations', '/property/map', 'workspace', 'Map'),
            new SearchResult('property.search.moderation', 'Property Moderation', '/property/submissions', 'workspace', 'Submissions'),
            new SearchResult('property.search.spatial', 'Spatial Workspace', '/spatial/manage', 'workspace', '3D / Spatial'),
            new SearchResult('property.search.catalog', 'Public Property Catalog', '/property/catalog', 'workspace', 'Catalog'),
        ], $query, $limit);

        $query = trim($query);
        if ($query === '') {
            return $navigation;
        }

        $entities = [];
        foreach ($this->properties->searchPropertyReferences($context->organizationId, $query, min($limit, 12)) as $reference) {
            $assetId = trim((string) ($reference['asset_id'] ?? ''));
            if ($assetId === '') {
                continue;
            }

            $presentation = $this->properties->getPropertyPresentation($context->organizationId, $assetId);
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

            $entities[] = new SearchResult(
                id: 'property.asset.' . $assetId,
                label: $label,
                path: $slug !== '' ? '/property/show/' . rawurlencode($slug) : '/property/manage',
                kind: 'entity',
                subtitle: implode(' · ', array_values(array_filter([
                    $assetId,
                    $location,
                    $type,
                    trim((string) ($inventory['status'] ?? '')),
                ]))),
                entity: new EntityRef('property.asset', $assetId),
                score: 91.0,
            );
        }

        return array_slice([...$entities, ...$navigation], 0, $limit);
    }

    public function commands(WebExtensionContext $context): array
    {
        return [
            new ShellCommandItem('property.open', 'Open Property Inventory', '/property/manage', 'navigation', 'Property'),
            new ShellCommandItem('property.map', 'Open Property Locations', '/property/map', 'navigation', 'Property'),
            new ShellCommandItem('property.spatial', 'Open Spatial Workspace', '/spatial/manage', 'navigation', 'Property'),
        ];
    }

    public function workspaces(WebExtensionContext $context): array
    {
        return [
            new WorkspaceDefinition('property.asset', 'Property Workspace', '/property/manage', 'property.asset', 10),
            new WorkspaceDefinition('property.spatial', 'Spatial Workspace', '/spatial/manage', 'spatial.scene', 20),
        ];
    }
}
