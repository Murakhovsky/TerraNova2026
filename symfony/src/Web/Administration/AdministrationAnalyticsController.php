<?php

declare(strict_types=1);

namespace App\Web\Administration;

use App\Application\Administration\Query\GetAdministrationAnalyticsQuery;
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

final readonly class AdministrationAnalyticsController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private QueryBusInterface $queries,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private AdministrationAnalyticsPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $days = max(7, min(365, (int) $request->query->get('days', 30)));
        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'administration',
            activeItem: 'analytics',
        );
        $shell = $this->shells->create($tenant, $context, 'Analytics', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Administration'),
            new ShellBreadcrumb('Analytics'),
        ]);

        try {
            $data = $this->queries->ask(new GetAdministrationAnalyticsQuery($days));
            $analytics = $this->presenter->present(is_array($data) ? $data : [], $days);

            return $this->render($shell, $analytics, $analytics->state());
        } catch (Throwable $error) {
            error_log('administration.analytics.read_failed ' . $error->getMessage());
            $analytics = $this->presenter->present([], $days, 'Аналітика тимчасово недоступна. Деталі записано в лог.');

            return $this->render($shell, $analytics, 'error', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function render(object $shell, object $analytics, string $state, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/administration/analytics.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::ExecutiveDashboard,
                    ['PageHeader', 'KpiStrip', 'FilterBar', 'DataGrid', 'EntityList', 'EmptyState', 'ErrorState'],
                    $state,
                ),
                'analytics' => $analytics,
            ]),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
