<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Application\Sales\Query\GetSalesLeadQuery;
use App\Application\Sales\Query\ListSalesLeadsQuery;
use App\Application\Sales\Admin\SalesAdminQuery;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\ProviderBackedShellNavigation;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\ShellConnectionState;
use App\Web\Experience\Shell\ShellViewModel;
use App\Web\Experience\Workspace\WorkspaceCompositionResolver;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final readonly class SalesWorkspaceController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private ProviderBackedShellNavigation $navigation,
        private WorkspaceCompositionResolver $workspaces,
    ) {
    }

    public function leads(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $data = $this->queries->ask(new ListSalesLeadsQuery($tenant->organizationId(), $request->query->all()));
        $context = $this->context($tenant, 'leads');

        return $this->render('experience/sales/leads.html.twig', [
            'shell' => $this->shell($tenant, $context, 'Lead List', [
                new ShellBreadcrumb('Workspace', '/admin'),
                new ShellBreadcrumb('Sales', '/sales/dashboard'),
                new ShellBreadcrumb('Leads'),
            ]),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
            'pagination' => is_array($data['pagination'] ?? null) ? $data['pagination'] : [],
            'filters' => [
                'q' => trim((string) $request->query->get('q', '')),
                'status' => trim((string) $request->query->get('status', '')),
                'source' => trim((string) $request->query->get('source', '')),
            ],
            'owners' => $this->owners($tenant),
            'csrfToken' => $this->csrf($request),
        ]);
    }

    public function lead(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $leadId = (int) $id;
        $lead = $this->queries->ask(new GetSalesLeadQuery($tenant->organizationId(), $leadId));
        if (!is_array($lead)) {
            throw new NotFoundHttpException('Lead not found.');
        }

        $context = $this->context($tenant, 'leads');
        $workspace = $this->workspaces->resolve(
            $tenant,
            $context,
            'sales.lead',
            new EntityRef('sales.lead', (string) $leadId),
        );

        return $this->render('experience/sales/lead_workspace.html.twig', [
            'shell' => $this->shell($tenant, $context, 'Lead Workspace', [
                new ShellBreadcrumb('Workspace', '/admin'),
                new ShellBreadcrumb('Sales', '/sales/dashboard'),
                new ShellBreadcrumb('Leads', '/sales/leads'),
                new ShellBreadcrumb((string) ($lead['name'] ?? ('Lead #' . $leadId))),
            ]),
            'workspace' => $workspace,
            'lead' => $lead,
            'owners' => $this->owners($tenant),
            'csrfToken' => $this->csrf($request),
        ]);
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function context(TenantContext $tenant, string $activeItem): WebExtensionContext
    {
        return new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: $activeItem,
        );
    }

    /** @param list<ShellBreadcrumb> $breadcrumbs */
    private function shell(TenantContext $tenant, WebExtensionContext $context, string $title, array $breadcrumbs): ShellViewModel
    {
        $navigation = $this->navigation->compose($context);
        $role = ucfirst($tenant->role()->value());
        $userId = $tenant->userId()->value();

        return new ShellViewModel(
            title: $title,
            tenantLabel: $tenant->organizationId()->value(),
            userLabel: $role . ' #' . $userId,
            userInitials: strtoupper(substr($role, 0, 2)),
            activeSection: 'sales',
            primaryNavigation: $navigation['primary'],
            utilityNavigation: $navigation['utility'],
            breadcrumbs: $breadcrumbs,
            commands: $navigation['commands'],
            connectionState: ShellConnectionState::Live,
            aiAvailable: true,
        );
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

    /** @param array<string,mixed> $variables */
    private function render(string $template, array $variables): Response
    {
        return new Response(
            $this->twig->render($template, $variables),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
