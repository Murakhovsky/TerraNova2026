<?php

declare(strict_types=1);

namespace Interfaces\Web\Navigation;

final class FrontendNavigation
{
    private const TEAM_ROLES = ['manager', 'admin'];

    public static function isTeam(string $role): bool
    {
        return in_array($role, self::TEAM_ROLES, true);
    }

    public static function public(): array
    {
        return [
            ['key' => 'home', 'path' => '', 'label' => 'Головна'],
            ['key' => 'catalog', 'path' => 'property/catalog', 'label' => 'Нерухомість'],
            ['key' => 'services', 'path' => 'services', 'label' => 'Послуги'],
            ['key' => 'partners', 'path' => 'partners', 'label' => 'Партнерам'],
            ['key' => 'about', 'path' => 'terra-nova', 'label' => 'Terra Nova'],
            ['key' => 'cos', 'path' => 'cos/en', 'label' => 'COS'],
        ];
    }

    public static function workspace(string $role): array
    {
        return self::workspaceCore($role);
    }

    public static function workspaceCore(string $role): array
    {
        $administrationChildren = [];
        if ($role === 'admin') {
            $administrationChildren[] = ['key' => 'users', 'path' => 'admin/users', 'label' => 'Users', 'order' => 10];
        }
        $administrationChildren[] = ['key' => 'content', 'path' => 'admin/content', 'label' => 'Content', 'order' => 20];

        return [
            'surface' => 'workspace',
            'primary' => [
                ['key' => 'home', 'path' => 'admin', 'label' => 'Огляд', 'glyph' => 'HM', 'order' => 10, 'children' => []],
                [
                    'key' => 'cos', 'path' => 'cos/control-center', 'label' => 'COS', 'glyph' => 'OS', 'order' => 50,
                    'children' => [
                        ['key' => 'cos', 'path' => 'cos/control-center', 'label' => 'Overview', 'order' => 10],
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
            ],
            'utility' => [
                ['key' => 'cabinet', 'path' => 'cabinet', 'label' => 'Кабінет', 'glyph' => 'ME', 'order' => 10],
            ],
        ];
    }

    public static function portal(string $role): array
    {
        return self::portalCore($role);
    }

    public static function portalCore(string $role): array
    {
        return [
            'surface' => 'portal',
            'primary' => [
                ['key' => 'cabinet', 'path' => 'cabinet', 'label' => 'Огляд', 'order' => 10],
            ],
            'utility' => [],
        ];
    }

    public static function activeSection(string $active): string
    {
        return match ($active) {
            'admin', 'home' => 'home',
            'sales', 'today', 'pipeline', 'leads', 'deals', 'director', 'sales-admin' => 'sales',
            'inbox', 'cases', 'clients' => 'clients',
            'objects', 'listing', 'locations', 'submissions', 'spatial', 'catalog', 'properties' => 'properties',
            'cos', 'diagnostics', 'actions', 'approvals', 'agents', 'rules', 'events', 'audit' => 'cos',
            'analytics' => 'analytics',
            'administration', 'content', 'users', 'settings', 'integrations' => 'administration',
            default => $active,
        };
    }
}
