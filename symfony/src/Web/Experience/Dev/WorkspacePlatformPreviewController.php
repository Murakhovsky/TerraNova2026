<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\ProviderBackedShellNavigation;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\ShellConnectionState;
use App\Web\Experience\Shell\ShellViewModel;
use App\Web\Experience\Workspace\WorkspaceCompositionResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

final readonly class WorkspacePlatformPreviewController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ProviderBackedShellNavigation $navigation,
        private WorkspaceCompositionResolver $workspaces,
    ) {
    }

    public function __invoke(): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->isManager()) {
            throw new AccessDeniedHttpException('Workspace Platform preview requires manager access.');
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'deals',
        );

        $workspace = $this->workspaces->resolve(
            $tenant,
            $context,
            'sales.deal',
            new EntityRef('sales.deal', 'deal-demo-42'),
        );

        $navigation = $this->navigation->compose($context);
        $role = ucfirst($tenant->role()->value());

        $shell = new ShellViewModel(
            title: $workspace->definition->label,
            tenantLabel: $tenant->organizationId()->value(),
            userLabel: $role . ' Workspace User',
            userInitials: strtoupper(substr($role, 0, 2)),
            activeSection: 'sales',
            primaryNavigation: $navigation['primary'],
            utilityNavigation: $navigation['utility'],
            breadcrumbs: [
                new ShellBreadcrumb('Workspace', '/admin'),
                new ShellBreadcrumb('Sales', '/sales/dashboard'),
                new ShellBreadcrumb('Deal'),
            ],
            commands: $navigation['commands'],
            notificationCount: 0,
            activityCount: 2,
            connectionState: ShellConnectionState::Live,
            aiAvailable: true,
        );

        return new Response(
            $this->twig->render('experience/workspace_platform_preview.html.twig', [
                'shell' => $shell,
                'workspace' => $workspace,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
