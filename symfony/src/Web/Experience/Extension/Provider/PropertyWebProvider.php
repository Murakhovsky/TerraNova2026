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
use App\Application\Experience\Search\Contract\PropertyEntitySearchInterface;

final class PropertyWebProvider implements NavigationProviderInterface, SearchProviderInterface, CommandProviderInterface, WorkspaceProviderInterface
{
    public function __construct(
        private readonly SearchResultMatcher $matcher,
        private readonly PropertyEntitySearchInterface $entitySearch,
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

        $entities = array_map(
            static fn ($hit): SearchResult => new SearchResult(
                id: $hit->id,
                label: $hit->label,
                path: $hit->path,
                kind: 'entity',
                subtitle: $hit->subtitle,
                entity: new EntityRef($hit->entityType, $hit->entityId),
                score: $hit->score,
            ),
            $this->entitySearch->search($context->organizationId, $query, $limit),
        );

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
            new WorkspaceDefinition('property.submission', 'Property Submission', '/property/submissions', 'property.submission', 20),
            new WorkspaceDefinition('property.spatial', 'Spatial Workspace', '/spatial/manage', 'spatial.scene', 30),
        ];
    }
}
