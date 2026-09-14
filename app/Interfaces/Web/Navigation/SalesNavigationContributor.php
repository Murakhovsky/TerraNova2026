<?php
declare(strict_types=1);

namespace Interfaces\Web\Navigation;

final class SalesNavigationContributor implements ModuleNavigationContributorInterface
{
    public function moduleId(): string
    {
        return 'sales';
    }

    public function workspacePrimary(string $role): array
    {
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

        return [
            [
                'key' => 'sales',
                'path' => 'sales/dashboard',
                'label' => 'Sales',
                'glyph' => 'SL',
                'order' => 20,
                'children' => $salesChildren,
            ],
            [
                'key' => 'clients',
                'path' => 'client-case/inbox',
                'label' => 'Клієнти',
                'glyph' => 'CL',
                'order' => 30,
                'children' => [
                    ['key' => 'inbox', 'path' => 'client-case/inbox', 'label' => 'Inbox', 'order' => 10],
                    ['key' => 'cases', 'path' => 'client-case', 'label' => 'Cases', 'order' => 20],
                ],
            ],
        ];
    }

    public function workspaceChildExtensions(string $role): array
    {
        return [];
    }

    public function portalPrimary(string $role): array
    {
        return [];
    }
}
