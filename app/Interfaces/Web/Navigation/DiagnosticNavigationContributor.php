<?php
declare(strict_types=1);

namespace Interfaces\Web\Navigation;

final class DiagnosticNavigationContributor implements ModuleNavigationContributorInterface
{
    public function moduleId(): string
    {
        return 'diagnostic';
    }

    public function workspacePrimary(string $role): array
    {
        return [];
    }

    public function workspaceChildExtensions(string $role): array
    {
        return [
            'cos' => [
                [
                    'key' => 'diagnostics',
                    'path' => 'admin/diagnostics/methodology-studio',
                    'label' => 'Diagnostics',
                    'order' => 80,
                ],
            ],
        ];
    }

    public function portalPrimary(string $role): array
    {
        return [];
    }
}
