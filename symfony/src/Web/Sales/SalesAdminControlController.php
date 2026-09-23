<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Admin\SalesAdminAuthorization;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class SalesAdminControlController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private SalesAdminAuthorization $authorization,
        private SalesAdminControlPresenter $presenter,
    ) {
    }

    public function pipelines(Request $request): Response
    {
        return $this->adminPage($request, 'pipelines', 'Sales Pipelines', 'page.pipelines');
    }

    public function pipeline(Request $request, string $id): Response
    {
        return $this->adminPage($request, 'pipeline', 'Pipeline Configuration', 'page.pipeline', $id);
    }

    public function rules(Request $request): Response
    {
        return $this->adminPage($request, 'rules', 'Sales Business Rules', 'page.rules');
    }

    public function rule(Request $request, string $id): Response
    {
        return $this->adminPage($request, 'rule', 'Business Rule Editor', 'page.rule', $id);
    }

    public function agents(Request $request): Response
    {
        return $this->adminPage($request, 'agents', 'Sales Intelligence Agents', 'page.agents');
    }

    public function agent(Request $request, string $name): Response
    {
        return $this->adminPage($request, 'agent', 'Sales Intelligence Agent', 'page.agent', $name);
    }

    public function actions(Request $request): Response
    {
        return $this->adminPage($request, 'actions', 'Actions & Policies Administration', 'page.actions');
    }

    public function teams(Request $request): Response
    {
        return $this->capabilityPage(
            $request,
            'teams',
            'Users, Teams & Authority',
            'page.teams',
            SalesAdminAuthorization::TEAMS,
        );
    }

    public function integrations(Request $request): Response
    {
        return $this->capabilityPage(
            $request,
            'integrations',
            'Sales Integrations Administration',
            'page.integrations',
            SalesAdminAuthorization::INTEGRATIONS,
        );
    }

    public function health(Request $request): Response
    {
        return $this->capabilityPage(
            $request,
            'health',
            'Sales Administration Health & Audit',
            'page.health',
            SalesAdminAuthorization::AUDIT,
        );
    }

    private function adminPage(
        Request $request,
        string $kind,
        string $title,
        string $operation,
        ?string $resourceId = null,
    ): Response {
        $tenant = $this->admin();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        return $this->page($request, $tenant, $kind, $title, $operation, $resourceId);
    }

    private function capabilityPage(
        Request $request,
        string $kind,
        string $title,
        string $operation,
        string $capability,
    ): Response {
        $tenant = $this->capability($capability);
        if ($tenant instanceof Response) {
            return $tenant;
        }

        return $this->page($request, $tenant, $kind, $title, $operation);
    }

    private function page(
        Request $request,
        TenantContext $tenant,
        string $kind,
        string $title,
        string $operation,
        ?string $resourceId = null,
    ): Response {
        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'system',
            activeSection: 'sales',
            activeItem: 'sales-admin',
        );
        $shell = $this->shells->create($tenant, $context, $title, [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Sales', '/sales/dashboard'),
            new ShellBreadcrumb('Administration', '/sales/admin'),
            new ShellBreadcrumb($title),
        ]);

        try {
            $data = $this->queries->ask(new SalesAdminQuery(
                organizationId: $tenant->organizationId(),
                operation: $operation,
                resourceId: $resourceId,
                input: ['limit' => 100],
            ));
            $admin = $this->presenter->present($kind, is_array($data) ? $data : []);

            return $this->render($kind, [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns($kind),
                    $admin->state(),
                ),
                'admin' => $admin,
                'csrfToken' => $this->csrf($request),
            ]);
        } catch (Throwable $error) {
            error_log('sales.admin.' . $kind . '.read_failed ' . $error->getMessage());
            $admin = $this->presenter->present(
                $kind,
                [],
                $title . ' тимчасово недоступний. Деталі записано в лог.',
            );

            return $this->render($kind, [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns($kind),
                    'error',
                ),
                'admin' => $admin,
                'csrfToken' => $this->csrf($request),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function admin(): TenantContext|Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        return $tenant->isAdmin()
            ? $tenant
            : new Response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    private function capability(string $capability): TenantContext|Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) {
            return $tenant;
        }
        if ($tenant->isAdmin()) {
            return $tenant;
        }

        $userId = (int) $tenant->userId()->value();
        if ($userId <= 0 || !$this->authorization->allows(
            $tenant->organizationId()->value(),
            $userId,
            $capability,
        )) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return $tenant;
    }

    private function authenticated(): TenantContext|Response
    {
        return $this->tenants->current() ?? new RedirectResponse('/auth/login');
    }

    private function csrf(Request $request): string
    {
        return $request->hasSession()
            ? (string) $request->getSession()->get('cos_csrf_token', '')
            : '';
    }

    /** @return list<string> */
    private function patterns(string $kind): array
    {
        $patterns = ['PageHeader', 'Toolbar', 'EntityList', 'ActionBar', 'EmptyState', 'ErrorState'];
        if ($kind === 'health') {
            $patterns[] = 'KpiStrip';
            $patterns[] = 'Timeline';
        }

        return $patterns;
    }

    /** @param array<string,mixed> $variables */
    private function render(
        string $kind,
        array $variables,
        int $status = Response::HTTP_OK,
    ): Response {
        return new Response(
            $this->twig->render('experience/sales/admin/' . $kind . '.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
