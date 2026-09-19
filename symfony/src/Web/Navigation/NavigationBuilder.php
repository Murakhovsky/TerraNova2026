<?php
declare(strict_types=1);

namespace App\Web\Navigation;

use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;

final readonly class NavigationBuilder
{
    public function __construct(private ActiveModuleResolver $modules)
    {
    }

    /** @return array{surface:string,primary:list<array<string,mixed>>,utility:list<array<string,mixed>>} */
    public function workspace(TenantContext $tenant): array
    {
        $role = $tenant->role()->value();
        $organizationId = $tenant->organizationId()->value();
        $snapshot = $this->modules->snapshot($organizationId);

        $administrationChildren = [];
        if ($role === 'admin') {
            $administrationChildren[] = ['key' => 'users', 'path' => 'admin/users', 'label' => 'Users', 'order' => 10];
        }
        $administrationChildren[] = ['key' => 'content', 'path' => 'admin/content', 'label' => 'Content', 'order' => 20];

        $primary = [
            ['key' => 'home', 'path' => 'admin', 'label' => 'Огляд', 'glyph' => 'HM', 'order' => 10, 'children' => []],
            [
                'key' => 'cos', 'path' => 'cos/control-center', 'label' => 'COS', 'glyph' => 'OS', 'order' => 50,
                'children' => [
                    ['key' => 'cos', 'path' => 'cos/control-center', 'label' => 'Overview', 'order' => 10],
                    ['key' => 'architecture', 'path' => 'cos/architecture', 'label' => 'Architecture', 'order' => 15],
                    ['key' => 'actions', 'path' => 'cos/control-center#actions', 'label' => 'Actions', 'order' => 20],
                    ['key' => 'approvals', 'path' => 'cos/control-center#approvals', 'label' => 'Approvals', 'order' => 30],
                    ['key' => 'agents', 'path' => 'cos/control-center#agents', 'label' => 'Agents', 'order' => 40],
                    ['key' => 'rules', 'path' => 'cos/control-center#rules', 'label' => 'Rules', 'order' => 50],
                    ['key' => 'events', 'path' => 'cos/control-center#events', 'label' => 'Events', 'order' => 60],
                    ['key' => 'audit', 'path' => 'cos/control-center#audit', 'label' => 'Audit', 'order' => 70],
                ],
            ],
            ['key' => 'analytics', 'path' => 'admin/analytics', 'label' => 'Аналітика', 'glyph' => 'AN', 'order' => 60, 'children' => []],
            [
                'key' => 'administration', 'path' => 'admin/content', 'label' => 'Administration', 'glyph' => 'AD', 'order' => 70,
                'children' => $administrationChildren,
            ],
        ];

        if ($snapshot->isEnabled('sales')) {
            $salesChildren = [
                ['key' => 'sales', 'path' => 'sales/dashboard', 'label' => 'Overview', 'order' => 10],
                ['key' => 'today', 'path' => 'sales/today', 'label' => 'Today', 'order' => 20],
                ['key' => 'pipeline', 'path' => 'sales/pipeline', 'label' => 'Pipeline', 'order' => 30],
                ['key' => 'leads', 'path' => 'sales/leads', 'label' => 'Leads', 'order' => 40],
                ['key' => 'deals', 'path' => 'sales/deals', 'label' => 'Deals', 'order' => 50],
                ['key' => 'director', 'path' => 'sales/director', 'label' => 'Director', 'order' => 60],
            ];
            if ($role === 'admin') {
                $salesChildren[] = ['key' => 'sales-admin', 'path' => 'sales/admin', 'label' => 'Sales Admin', 'order' => 70];
            }
            $primary[] = [
                'key' => 'sales', 'path' => 'sales/dashboard', 'label' => 'Sales', 'glyph' => 'SL', 'order' => 20,
                'children' => $salesChildren,
            ];
            $primary[] = [
                'key' => 'clients', 'path' => 'client-case/inbox', 'label' => 'Клієнти', 'glyph' => 'CL', 'order' => 30,
                'children' => [
                    ['key' => 'inbox', 'path' => 'client-case/inbox', 'label' => 'Inbox', 'order' => 10],
                    ['key' => 'cases', 'path' => 'client-case', 'label' => 'Cases', 'order' => 20],
                ],
            ];
        }

        if ($snapshot->isEnabled('property')) {
            $primary[] = [
                'key' => 'properties', 'path' => 'property/manage', 'label' => 'Нерухомість', 'glyph' => 'RE', 'order' => 40,
                'children' => [
                    ['key' => 'objects', 'path' => 'property/manage', 'label' => 'Inventory', 'order' => 10],
                    ['key' => 'listing', 'path' => 'property/listing', 'label' => 'Listing', 'order' => 20],
                    ['key' => 'locations', 'path' => 'property/map', 'label' => 'Locations', 'order' => 30],
                    ['key' => 'submissions', 'path' => 'property/submissions', 'label' => 'Модерація', 'order' => 40],
                    ['key' => 'spatial', 'path' => 'spatial/manage', 'label' => '3D / Spatial', 'order' => 50],
                    ['key' => 'catalog', 'path' => 'property/catalog', 'label' => 'Публічний каталог', 'order' => 60],
                ],
            ];
        }

        if ($snapshot->isEnabled('diagnostic')) {
            foreach ($primary as &$item) {
                if (($item['key'] ?? '') !== 'cos') {
                    continue;
                }
                $item['children'][] = [
                    'key' => 'diagnostics',
                    'path' => 'admin/diagnostics/methodology-studio',
                    'label' => 'Diagnostics',
                    'order' => 80,
                ];
            }
            unset($item);
        }

        return [
            'surface' => 'workspace',
            'primary' => $this->normalize($primary),
            'utility' => [
                ['key' => 'cabinet', 'path' => 'cabinet', 'label' => 'Кабінет', 'glyph' => 'ME'],
            ],
        ];
    }

    /** @return array{surface:string,primary:list<array<string,mixed>>,utility:list<array<string,mixed>>} */
    public function portal(TenantContext $tenant): array
    {
        return [
            'surface' => 'portal',
            'primary' => [
                ['key' => 'cabinet', 'path' => 'cabinet', 'label' => 'Огляд'],
                ['key' => 'requests', 'path' => 'cabinet#requests', 'label' => 'Звернення'],
                ['key' => 'profile', 'path' => 'cabinet#profile', 'label' => 'Профіль'],
            ],
            'utility' => [],
        ];
    }

    public function activeSection(string $active): string
    {
        return match ($active) {
            'admin', 'home' => 'home',
            'sales', 'today', 'pipeline', 'leads', 'deals', 'director', 'sales-admin' => 'sales',
            'inbox', 'cases', 'clients' => 'clients',
            'objects', 'listing', 'locations', 'submissions', 'spatial', 'catalog', 'properties' => 'properties',
            'cos', 'architecture', 'diagnostics', 'actions', 'approvals', 'agents', 'rules', 'events', 'audit' => 'cos',
            'analytics' => 'analytics',
            'administration', 'content', 'users', 'settings', 'integrations' => 'administration',
            default => $active,
        };
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function normalize(array $items): array
    {
        usort($items, static fn(array $a, array $b): int => ((int) ($a['order'] ?? 1000)) <=> ((int) ($b['order'] ?? 1000)));
        foreach ($items as &$item) {
            unset($item['order']);
            if (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->normalize($item['children']);
            }
        }
        unset($item);

        return array_values($items);
    }
}
