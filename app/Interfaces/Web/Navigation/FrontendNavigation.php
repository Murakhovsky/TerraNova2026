<?php

declare(strict_types=1);

namespace Interfaces\Web\Navigation;

final class FrontendNavigation
{
    private const TEAM_ROLES = ['manager', 'admin'];
    private const LISTING_ROLES = ['manager', 'admin', 'realtor', 'partner', 'developer'];
    private const SUBMIT_ROLES = ['seller', 'realtor', 'developer', 'partner', 'manager', 'admin'];

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
        $utility = [
            ['key' => 'cabinet', 'path' => 'cabinet', 'label' => 'Кабінет', 'glyph' => 'ME'],
        ];

        $salesChildren = [
            ['key' => 'sales', 'path' => 'sales/dashboard', 'label' => 'Overview'],
            ['key' => 'today', 'path' => 'sales/today', 'label' => 'Today'],
            ['key' => 'pipeline', 'path' => 'sales/pipeline', 'label' => 'Pipeline'],
            ['key' => 'leads', 'path' => 'sales/leads', 'label' => 'Leads'],
            ['key' => 'deals', 'path' => 'sales/deals', 'label' => 'Deals'],
            ['key' => 'director', 'path' => 'sales/director', 'label' => 'Director'],
        ];
        if ($role === 'admin') {
            $salesChildren[] = ['key' => 'sales-admin', 'path' => 'sales/admin', 'label' => 'Sales Admin'];
        }

        $administrationChildren = [];
        if ($role === 'admin') {
            $administrationChildren[] = ['key' => 'users', 'path' => 'admin/users', 'label' => 'Users'];
        }
        $administrationChildren[] = ['key' => 'content', 'path' => 'admin/content', 'label' => 'Content'];

        return [
            'surface' => 'workspace',
            'primary' => [
                ['key' => 'home', 'path' => 'admin', 'label' => 'Огляд', 'glyph' => 'HM', 'children' => []],
                ['key' => 'sales', 'path' => 'sales/dashboard', 'label' => 'Sales', 'glyph' => 'SL', 'children' => $salesChildren],
                [
                    'key' => 'clients', 'path' => 'client-case/inbox', 'label' => 'Клієнти', 'glyph' => 'CL',
                    'children' => [
                        ['key' => 'inbox', 'path' => 'client-case/inbox', 'label' => 'Inbox'],
                        ['key' => 'cases', 'path' => 'client-case', 'label' => 'Cases'],
                    ],
                ],
                [
                    'key' => 'properties', 'path' => 'property/manage', 'label' => 'Нерухомість', 'glyph' => 'RE',
                    'children' => [
                        ['key' => 'objects', 'path' => 'property/manage', 'label' => 'Inventory'],
                        ['key' => 'listing', 'path' => 'property/listing', 'label' => 'Listing'],
                        ['key' => 'locations', 'path' => 'property/map', 'label' => 'Locations'],
                        ['key' => 'submissions', 'path' => 'property/submissions', 'label' => 'Модерація'],
                        ['key' => 'spatial', 'path' => 'spatial/manage', 'label' => '3D / Spatial'],
                        ['key' => 'catalog', 'path' => 'property/catalog', 'label' => 'Публічний каталог'],
                    ],
                ],
                [
                    'key' => 'cos', 'path' => 'cos/control-center', 'label' => 'COS', 'glyph' => 'OS',
                    'children' => [
                        ['key' => 'cos', 'path' => 'cos/control-center', 'label' => 'Overview'],
                        ['key' => 'actions', 'path' => 'cos/control-center#actions', 'label' => 'Actions'],
                        ['key' => 'approvals', 'path' => 'cos/control-center#approvals', 'label' => 'Approvals'],
                        ['key' => 'agents', 'path' => 'cos/control-center#agents', 'label' => 'Agents'],
                        ['key' => 'rules', 'path' => 'cos/control-center#rules', 'label' => 'Rules'],
                        ['key' => 'events', 'path' => 'cos/control-center#events', 'label' => 'Events'],
                        ['key' => 'audit', 'path' => 'cos/control-center#audit', 'label' => 'Audit'],
                        ['key' => 'diagnostics', 'path' => 'admin/diagnostics/methodology-studio', 'label' => 'Diagnostics'],
                    ],
                ],
                ['key' => 'analytics', 'path' => 'admin/analytics', 'label' => 'Аналітика', 'glyph' => 'AN', 'children' => []],
                [
                    'key' => 'administration', 'path' => 'admin/content', 'label' => 'Administration', 'glyph' => 'AD',
                    'children' => $administrationChildren,
                ],
            ],
            'utility' => $utility,
        ];
    }

    public static function portal(string $role): array
    {
        $items = [
            ['key' => 'cabinet', 'path' => 'cabinet', 'label' => 'Огляд'],
            ['key' => 'catalog', 'path' => 'property/catalog', 'label' => 'Нерухомість'],
            ['key' => 'favour', 'path' => 'property/favour', 'label' => 'Вибрані'],
        ];
        if (in_array($role, self::LISTING_ROLES, true)) $items[] = ['key' => 'listing', 'path' => 'property/listing', 'label' => 'Мої обʼєкти'];
        if (in_array($role, self::SUBMIT_ROLES, true)) $items[] = ['key' => 'submit', 'path' => 'property/submit', 'label' => 'Подати обʼєкт'];
        return ['surface' => 'portal', 'primary' => $items, 'utility' => []];
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
