<?php

declare(strict_types=1);

namespace App\Web\Workspace;

use App\Application\Experience\Query\GetExecutiveDashboardQuery;
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

final readonly class ExecutiveDashboardController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private ExecutiveDashboardPresenter $presenter,
    ) {
    }

    public function index(): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }

        $context = $this->context($tenant);
        $shell = $this->shells->create($tenant, $context, 'Огляд компанії', [
            new ShellBreadcrumb('Workspace'),
            new ShellBreadcrumb('Огляд'),
        ]);

        if (!$tenant->isManager()) {
            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::ExecutiveDashboard,
                    $this->patterns(),
                    'permission_denied',
                ),
                'dashboard' => $this->presenter->present([]),
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $snapshot = $this->queries->ask(new GetExecutiveDashboardQuery($tenant->organizationId()));
            $dashboard = $this->presenter->present(is_array($snapshot) ? $snapshot : []);

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::ExecutiveDashboard,
                    $this->patterns(),
                    $dashboard->state(),
                ),
                'dashboard' => $dashboard,
            ]);
        } catch (Throwable $error) {
            error_log('workspace.executive_dashboard.read_failed ' . $error->getMessage());
            $dashboard = $this->presenter->present([], 'Огляд компанії тимчасово недоступний. Деталі записано в лог.');

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::ExecutiveDashboard,
                    $this->patterns(),
                    'error',
                ),
                'dashboard' => $dashboard,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function context(TenantContext $tenant): WebExtensionContext
    {
        return new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'home',
            activeItem: 'home',
        );
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return [
            'PageHeader',
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
            $this->twig->render('experience/admin/dashboard.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
