<?php

declare(strict_types=1);

namespace App\Web\Workspace;

use App\Application\Experience\Query\GetWorkspaceAnalyticsQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class AnalyticsDashboardController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private AnalyticsDashboardPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $days = max(7, min(365, (int) $request->query->get('days', 30)));
        $shell = $this->shells->create(
            $tenant,
            new WebExtensionContext(
                organizationId: $tenant->organizationId()->value(),
                role: $tenant->role()->value(),
                surface: 'workspace',
                activeSection: 'analytics',
                activeItem: 'analytics',
            ),
            'Аналітика',
            [
                new ShellBreadcrumb('Workspace', '/admin'),
                new ShellBreadcrumb('Аналітика'),
            ],
        );

        try {
            $report = $this->queries->ask(new GetWorkspaceAnalyticsQuery($days));
            $analytics = $this->presenter->present(is_array($report) ? $report : [], $days);

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::ExecutiveDashboard,
                    $this->patterns(),
                    $analytics->state(),
                ),
                'analytics' => $analytics,
            ]);
        } catch (Throwable $error) {
            error_log('workspace.analytics.read_failed ' . $error->getMessage());
            $analytics = $this->presenter->present(
                [],
                $days,
                'Аналітика тимчасово недоступна. Деталі записано в лог.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::ExecutiveDashboard,
                    $this->patterns(),
                    'error',
                ),
                'analytics' => $analytics,
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
        return [
            'PageHeader',
            'KpiStrip',
            'FilterBar',
            'DataGrid',
            'EntityList',
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/admin/analytics.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
