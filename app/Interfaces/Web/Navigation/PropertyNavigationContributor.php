<?php
declare(strict_types=1);

namespace Interfaces\Web\Navigation;

final class PropertyNavigationContributor implements ModuleNavigationContributorInterface
{
    private const LISTING_ROLES = ['manager', 'admin', 'realtor', 'partner', 'developer'];
    private const SUBMIT_ROLES = ['seller', 'realtor', 'developer', 'partner', 'manager', 'admin'];

    public function moduleId(): string
    {
        return 'property';
    }

    public function workspacePrimary(string $role): array
    {
        return [[
            'key' => 'properties',
            'path' => 'property/manage',
            'label' => 'Нерухомість',
            'glyph' => 'RE',
            'order' => 40,
            'children' => [
                ['key' => 'objects', 'path' => 'property/manage', 'label' => 'Inventory', 'order' => 10],
                ['key' => 'listing', 'path' => 'property/listing', 'label' => 'Listing', 'order' => 20],
                ['key' => 'locations', 'path' => 'property/map', 'label' => 'Locations', 'order' => 30],
                ['key' => 'submissions', 'path' => 'property/submissions', 'label' => 'Модерація', 'order' => 40],
                ['key' => 'spatial', 'path' => 'spatial/manage', 'label' => '3D / Spatial', 'order' => 50],
                ['key' => 'catalog', 'path' => 'property/catalog', 'label' => 'Публічний каталог', 'order' => 60],
            ],
        ]];
    }

    public function workspaceChildExtensions(string $role): array
    {
        return [];
    }

    public function portalPrimary(string $role): array
    {
        $items = [
            ['key' => 'catalog', 'path' => 'property/catalog', 'label' => 'Нерухомість', 'order' => 20],
            ['key' => 'favour', 'path' => 'property/favour', 'label' => 'Вибрані', 'order' => 30],
        ];
        if (in_array($role, self::LISTING_ROLES, true)) {
            $items[] = ['key' => 'listing', 'path' => 'property/listing', 'label' => 'Мої обʼєкти', 'order' => 40];
        }
        if (in_array($role, self::SUBMIT_ROLES, true)) {
            $items[] = ['key' => 'submit', 'path' => 'property/submit', 'label' => 'Подати обʼєкт', 'order' => 50];
        }

        return $items;
    }
}
