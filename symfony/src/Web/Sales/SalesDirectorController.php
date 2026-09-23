<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Query\GetSalesDirectorDashboardQuery;
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

final readonly class SalesDirectorController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private SalesDirectorPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $historyDays = max(1, min(366, (int) $request->query->get('history_days', 30)));
        $forecastDays = max(1, min(366, (int) $request->query->get('forecast_days', 30)));
        $pipelineId = trim((string) $request->query->get('pipeline_id', '')) ?: null;

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'director',
        );
        $shell = $this->shells->create($tenant, $context, 'Sales Director', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Sales', '/sales/dashboard'),
            new ShellBreadcrumb('Director'),
        ]);

        try {
            $data = $this->queries->ask(new GetSalesDirectorDashboardQuery(
                organizationId: $tenant->organizationId(),
                historyDays: $historyDays,
                forecastDays: $forecastDays,
                pipelineId: $pipelineId,
            ));
            $director = $this->presenter->present(
                is_array($data) ? $data : [],
                $historyDays,
                $forecastDays,
                $pipelineId,
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::ExecutiveDashboard,
                    $this->patterns(),
                    $director->state(),
                ),
                'director' => $director,
            ]);
        } catch (Throwable $error) {
            error_log('sales.director.read_failed ' . $error->getMessage());
            $director = $this->presenter->present(
                [],
                $historyDays,
                $forecastDays,
                $pipelineId,
                'Sales Director тимчасово недоступний. Деталі записано в лог.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::ExecutiveDashboard,
                    $this->patterns(),
                    'error',
                ),
                'director' => $director,
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
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/sales/director.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
