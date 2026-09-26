<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\ProviderBackedShellNavigation;
use Kernel\Tenant\Model\TenantContext;

final readonly class WorkspaceShellFactory
{
    public function __construct(private ProviderBackedShellNavigation $navigation)
    {
    }

    /**
     * @param list<ShellBreadcrumb> $breadcrumbs
     */
    public function create(
        TenantContext $tenant,
        WebExtensionContext $context,
        string $title,
        array $breadcrumbs = [],
    ): ShellViewModel {
        $navigation = $this->navigation->compose($context);
        $role = ucfirst($tenant->role()->value());
        $userId = $tenant->userId()->value();

        return new ShellViewModel(
            title: $title,
            tenantLabel: $tenant->organizationId()->value(),
            userLabel: $role . ' #' . $userId,
            userInitials: strtoupper(substr($role, 0, 2)),
            activeSection: $context->activeSection,
            primaryNavigation: $navigation['primary'],
            utilityNavigation: $navigation['utility'],
            breadcrumbs: $breadcrumbs,
            commands: $navigation['commands'],
            connectionState: ShellConnectionState::Live,
            aiAvailable: true,
        );
    }
}
