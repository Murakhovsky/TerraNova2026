<?php
declare(strict_types=1);

namespace Interfaces\Web\Navigation;

interface ModuleNavigationContributorInterface
{
    public function moduleId(): string;

    /** @return list<array<string, mixed>> */
    public function workspacePrimary(string $role): array;

    /** @return array<string, list<array<string, mixed>>> */
    public function workspaceChildExtensions(string $role): array;

    /** @return list<array<string, mixed>> */
    public function portalPrimary(string $role): array;
}
