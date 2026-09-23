<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Admin\SalesAdminQuery;
use App\Application\Sales\Query\ListSalesLeadsQuery;
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

final readonly class SalesLeadsController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private SalesLeadsPresenter $presenter,
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
            'source' => trim((string) $request->query->get('source', '')),
        ];

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'leads',
        );
        $shell = $this->shells->create($tenant, $context, 'Lead List', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Sales', '/sales/dashboard'),
            new ShellBreadcrumb('Leads'),
        ]);

        try {
            $data = $this->queries->ask(
                new ListSalesLeadsQuery($tenant->organizationId(), $request->query->all()),
            );
            $leads = $this->presenter->present(
                is_array($data) ? $data : [],
                $filters,
                $this->owners($tenant),
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::Collection,
                    $this->patterns(),
                    $leads->state(),
                ),
                'leads' => $leads,
                'csrfToken' => $this->csrf($request),
            ]);
        } catch (Throwable $error) {
            error_log('sales.leads.read_failed ' . $error->getMessage());
            $leads = $this->presenter->present(
                [],
                $filters,
                [],
                'Lead List тимчасово недоступний. Деталі записано в лог.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::Collection,
                    $this->patterns(),
                    'error',
                ),
                'leads' => $leads,
                'csrfToken' => $this->csrf($request),
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

    /** @return list<array<string,mixed>> */
    private function owners(TenantContext $tenant): array
    {
        $users = $this->queries->ask(new SalesAdminQuery($tenant->organizationId(), 'team.users'));
        if (!is_array($users)) {
            return [];
        }

        return array_values(array_filter(
            $users,
            static fn (mixed $user): bool => is_array($user)
                && strtolower((string) ($user['status'] ?? '')) === 'active'
                && in_array(
                    strtolower((string) ($user['organization_role'] ?? $user['role'] ?? '')),
                    ['manager', 'admin'],
                    true,
                ),
        ));
    }

    private function csrf(Request $request): string
    {
        return $request->hasSession()
            ? (string) $request->getSession()->get('cos_csrf_token', '')
            : '';
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return [
            'PageHeader',
            'FilterBar',
            'EntityList',
            'ActionBar',
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/sales/leads.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
