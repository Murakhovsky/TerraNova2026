<?php

declare(strict_types=1);

namespace App\Web\Spatial;

use App\Application\Spatial\Query\GetSpatialManageQuery;
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

final readonly class SpatialManageController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private SpatialManagePresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'status' => trim((string) $request->query->get('status', '')),
        ];

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'properties',
            activeItem: 'spatial',
        );
        $shell = $this->shells->create($tenant, $context, 'Spatial / 3D', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Properties', '/property/manage'),
            new ShellBreadcrumb('Spatial'),
        ]);

        try {
            $data = $this->queries->ask(new GetSpatialManageQuery($filters));
            $spatial = $this->presenter->present(
                is_array($data) ? $data : [],
                (string) $request->query->get('status_message', ''),
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(PageArchetype::MapSpatial, $this->patterns(), $spatial->state()),
                'spatial' => $spatial,
            ]);
        } catch (Throwable $error) {
            error_log('spatial.manage.read_failed ' . $error->getMessage());
            $spatial = $this->presenter->present(
                ['filters' => $filters],
                (string) $request->query->get('status_message', ''),
                'Spatial inventory тимчасово недоступний.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(PageArchetype::MapSpatial, $this->patterns(), 'error'),
                'spatial' => $spatial,
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
            'Toolbar',
            'ContextPanel',
            'KpiStrip',
            'FilterBar',
            'EntityList',
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/spatial/manage.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
