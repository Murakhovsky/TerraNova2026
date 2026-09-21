<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Shell\ShellCommandItem;

final class PropertyWebProvider implements NavigationProviderInterface, CommandProviderInterface, WorkspaceProviderInterface
{
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
