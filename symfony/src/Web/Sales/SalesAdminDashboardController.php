<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Admin\SalesAdminQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class SalesAdminDashboardController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private SalesAdminDashboardPresenter $presenter,
    ) {
    }

    public function index(): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'system',
            activeSection: 'sales',
            activeItem: 'sales-admin',
        );
        $shell = $this->shells->create($tenant, $context, 'Sales Administration', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Sales', '/sales/dashboard'),
            new ShellBreadcrumb('Administration'),
        ]);

        try {
            $data = $this->queries->ask(
                new SalesAdminQuery($tenant->organizationId(), 'dashboard'),
            );
            $admin = $this->presenter->present(is_array($data) ? $data : []);

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns(),
                    $admin->state(),
                ),
                'admin' => $admin,
            ]);
        } catch (Throwable $error) {
            error_log('sales.admin.dashboard.read_failed ' . $error->getMessage());
            $admin = $this->presenter->present(
                [],
                'Sales Administration тимчасово недоступний. Деталі записано в лог.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns(),
                    'error',
                ),
                'admin' => $admin,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function admin(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isAdmin()) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return $tenant;
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return [
            'PageHeader',
            'Toolbar',
            'KpiStrip',
            'EntityList',
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/sales/admin/dashboard.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
