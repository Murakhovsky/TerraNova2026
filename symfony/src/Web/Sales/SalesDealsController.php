<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Query\GetSalesDealsCollectionQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Data\DataGridQuery;
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

final readonly class SalesDealsController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private SalesDealsPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $gridQuery = $this->gridQuery($request);
        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'deals',
        );
        $shell = $this->shells->create($tenant, $context, 'Sales Deals', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Sales', '/sales/dashboard'),
            new ShellBreadcrumb('Deals'),
        ]);

        try {
            $data = $this->queries->ask(new GetSalesDealsCollectionQuery(
                organizationId: $tenant->organizationId(),
                search: $gridQuery->search,
                filters: $gridQuery->filters,
                page: $gridQuery->page,
                perPage: $gridQuery->perPage,
            ));
            $deals = $this->presenter->present(
                is_array($data) ? $data : [],
                $gridQuery,
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::Collection,
                    $this->patterns(),
                    $deals->state(),
                ),
                'deals' => $deals,
            ]);
        } catch (Throwable $error) {
            error_log('sales.deals.read_failed ' . $error->getMessage());
            $deals = $this->presenter->present(
                [],
                $gridQuery,
                'Sales Deals тимчасово недоступні. Деталі записано в лог.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::Collection,
                    $this->patterns(),
                    'error',
                ),
                'deals' => $deals,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function gridQuery(Request $request): DataGridQuery
    {
        $input = $request->query->all();
        $filters = is_array($input['filter'] ?? null) ? $input['filter'] : [];

        foreach (['owner_id', 'priority', 'source', 'pipeline_id', 'stage_id', 'risk'] as $key) {
            if (array_key_exists($key, $filters)) {
                continue;
            }

            $value = trim((string) $request->query->get($key, ''));
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $input['filter'] = $filters;

        return DataGridQuery::fromArray($input);
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
            'Toolbar',
            'DataGrid',
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/sales/deals.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
