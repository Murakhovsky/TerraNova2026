<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Query\GetSalesDashboardQuery;
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

final readonly class SalesDashboardController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private SalesDashboardPresenter $presenter,
    ) {
    }

    public function index(): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'sales-overview',
        );
        $shell = $this->shells->create($tenant, $context, 'Sales Dashboard', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Sales'),
            new ShellBreadcrumb('Dashboard'),
        ]);

        try {
            $actor = $tenant->userId()->value();
            $ownerId = ctype_digit($actor) ? (int) $actor : null;
            $data = $this->queries->ask(new GetSalesDashboardQuery($tenant->organizationId(), $ownerId));
            $dashboard = $this->presenter->present(is_array($data) ? $data : []);

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(PageArchetype::DomainDashboard, $this->patterns(), $dashboard->state()),
                'dashboard' => $dashboard,
            ]);
        } catch (Throwable $error) {
            error_log('sales.dashboard.read_failed ' . $error->getMessage());
            $dashboard = $this->presenter->present([], 'Sales Dashboard тимчасово недоступний. Деталі записано в лог.');

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(PageArchetype::DomainDashboard, $this->patterns(), 'error'),
                'dashboard' => $dashboard,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isManager()) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return $tenant;
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return ['PageHeader', 'KpiStrip', 'EntityList', 'EmptyState', 'ErrorState'];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/sales/dashboard.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
