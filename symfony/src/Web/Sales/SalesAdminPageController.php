<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Security\LegacySessionReader;
use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Sales\Application\Contract\SalesAccessControlInterface;
use Domains\Sales\Application\Contract\SalesAdministrationReadModelInterface;
use Domains\Sales\Application\Contract\SalesAgentAdministrationInterface;
use Domains\Sales\Application\Contract\SalesIntegrationAdministrationInterface;
use Domains\Sales\Application\Contract\SalesPipelineAdministrationInterface;
use Domains\Sales\Application\Contract\SalesPolicyAdministrationInterface;
use Domains\Sales\Application\Contract\SalesRuleAdministrationInterface;
use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Domains\Sales\Model\SalesCapability;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class SalesAdminPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private LegacySessionReader $sessions,
        private NavigationBuilder $navigation,
        private SalesWorkspaceOperationalReadModelInterface $workspace,
        private SalesPipelineAdministrationInterface $pipelines,
        private SalesRuleAdministrationInterface $rules,
        private SalesAgentAdministrationInterface $agents,
        private SalesPolicyAdministrationInterface $policies,
        private SalesTeamAdministrationInterface $teams,
        private SalesIntegrationAdministrationInterface $integrations,
        private SalesAdministrationReadModelInterface $health,
        private SalesAccessControlInterface $access,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        return $this->safe($request, $tenant, 'Sales Administration', 'sales-admin', 'sales/admin', fn() => [
            'workspace' => [
                'pipelines' => $this->workspace->pipelines($tenant->organizationId()->value()),
                'metrics' => $this->workspace->metrics($tenant->organizationId()->value(), 30),
            ],
        ]);
    }

    public function pipelines(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        return $this->safe($request, $tenant, 'Sales Pipelines', 'sales-admin', 'sales_admin/pipelines', fn() => [
            'pipelines' => $this->pipelines->pipelines($tenant->organizationId()->value()),
        ]);
    }

    public function pipeline(Request $request, string $id): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        try {
            $pipeline = $this->pipelines->pipeline($tenant->organizationId()->value(), trim($id));
            if ($pipeline === null) {
                return $this->failure($request, $tenant, 404, 'Сторінку не знайдено', 'Pipeline не знайдено.');
            }
            return $this->render($request, $tenant, 'Pipeline Configuration', 'sales-admin', 'sales_admin/pipeline', [
                'pipeline' => $pipeline,
            ]);
        } catch (Throwable $error) {
            return $this->exception($request, $tenant, $error, 'sales_admin.pipeline');
        }
    }

    public function rules(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        return $this->safe($request, $tenant, 'Sales Business Rules', 'sales-admin', 'sales_admin/rules', fn() => [
            'rules' => $this->rules->rules($tenant->organizationId()->value()),
            'ruleCatalog' => $this->rules->catalog(),
        ]);
    }

    public function rule(Request $request, string $id): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        try {
            $rule = $this->rules->rule($tenant->organizationId()->value(), trim($id));
            if ($rule === null) {
                return $this->failure($request, $tenant, 404, 'Сторінку не знайдено', 'Business rule не знайдено.');
            }
            return $this->render($request, $tenant, 'Business Rule Editor', 'sales-admin', 'sales_admin/rule', [
                'rule' => $rule,
                'ruleCatalog' => $this->rules->catalog(),
                'ruleRevisions' => $this->rules->revisions($tenant->organizationId()->value(), trim($id), 50),
            ]);
        } catch (Throwable $error) {
            return $this->exception($request, $tenant, $error, 'sales_admin.rule');
        }
    }

    public function agents(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        return $this->safe($request, $tenant, 'Sales Intelligence Agents', 'sales-admin', 'sales_admin/agents', fn() => [
            'agents' => $this->agents->agents($tenant->organizationId()->value()),
            'agentCatalog' => $this->agents->catalog(),
        ]);
    }

    public function agent(Request $request, string $name): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        try {
            $agent = $this->agents->agent($tenant->organizationId()->value(), trim($name));
            if ($agent === null) {
                return $this->failure($request, $tenant, 404, 'Сторінку не знайдено', 'Sales agent не знайдено.');
            }
            return $this->render($request, $tenant, 'Sales Intelligence Agent', 'sales-admin', 'sales_admin/agent', [
                'agent' => $agent,
                'agentCatalog' => $this->agents->catalog(),
                'agentRevisions' => $this->agents->revisions($tenant->organizationId()->value(), trim($name), 50),
            ]);
        } catch (Throwable $error) {
            return $this->exception($request, $tenant, $error, 'sales_admin.agent');
        }
    }

    public function actions(Request $request): Response
    {
        $tenant = $this->admin();
        if ($tenant instanceof Response) return $tenant;

        return $this->safe($request, $tenant, 'Actions & Policies Administration', 'sales-admin', 'sales_admin/actions', fn() => [
            'actions' => $this->policies->actions($tenant->organizationId()->value()),
            'policyCatalog' => $this->policies->catalog($tenant->organizationId()->value()),
        ]);
    }

    public function teams(Request $request): Response
    {
        $tenant = $this->capability(SalesCapability::AdminTeamsManage);
        if ($tenant instanceof Response) return $tenant;

        return $this->safe($request, $tenant, 'Users, Teams & Authority', 'sales-admin', 'sales_admin/teams', fn() => [
            'teams' => $this->teams->teams($tenant->organizationId()->value()),
            'users' => $this->teams->users($tenant->organizationId()->value()),
            'teamCatalog' => $this->teams->catalog(),
        ]);
    }

    public function integrations(Request $request): Response
    {
        $tenant = $this->capability(SalesCapability::AdminIntegrationsManage);
        if ($tenant instanceof Response) return $tenant;

        return $this->safe($request, $tenant, 'Sales Integrations Administration', 'sales-admin', 'sales_admin/integrations', function() use ($tenant): array {
            $organizationId = $tenant->organizationId()->value();
            $items = $this->integrations->integrations($organizationId);
            foreach ($items as &$item) {
                $full = $this->integrations->integration($organizationId, (int) ($item['id'] ?? 0));
                if ($full !== null) {
                    $item = array_merge($item, $full);
                }
            }
            unset($item);

            return [
                'integrations' => $items,
                'integrationCatalog' => $this->integrations->catalog(),
                'routingOptions' => $this->integrations->routingOptions($organizationId),
            ];
        });
    }

    public function health(Request $request): Response
    {
        $tenant = $this->capability(SalesCapability::AdminAuditView);
        if ($tenant instanceof Response) return $tenant;

        return $this->safe($request, $tenant, 'Sales Administration Health & Audit', 'sales-admin', 'sales_admin/health', fn() => [
            'administrationHealth' => $this->health->dashboard($tenant->organizationId()->value(), 50),
        ]);
    }

    /** @param callable():array<string,mixed> $reader */
    private function safe(
        Request $request,
        TenantContext $tenant,
        string $title,
        string $active,
        string $view,
        callable $reader,
    ): Response {
        try {
            return $this->render($request, $tenant, $title, $active, $view, $reader());
        } catch (Throwable $error) {
            return $this->exception($request, $tenant, $error, str_replace('/', '.', $view));
        }
    }

    private function exception(Request $request, TenantContext $tenant, Throwable $error, string $label): Response
    {
        error_log(sprintf('%s [%s] %s', $label, $error::class, $error->getMessage()));
        return $this->failure($request, $tenant, 503, 'Сервіс тимчасово недоступний', 'Sales Admin тимчасово недоступний.');
    }

    private function admin(): TenantContext|Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) return $tenant;
        if (!$tenant->isAdmin()) return new Response('Forbidden', 403);
        return $tenant;
    }

    private function capability(SalesCapability $capability): TenantContext|Response
    {
        $tenant = $this->authenticated();
        if ($tenant instanceof Response) return $tenant;
        if ($tenant->isAdmin()) return $tenant;

        $actorId = (int) $tenant->userId()->value();
        if ($actorId <= 0 || !$this->access->hasCapability(
            $tenant->organizationId()->value(),
            $actorId,
            $capability->value,
        )) {
            return new Response('Forbidden', 403);
        }

        return $tenant;
    }

    private function authenticated(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        return $tenant ?? new RedirectResponse('/auth/login');
    }

    /** @param array<string,mixed> $extra */
    private function render(
        Request $request,
        TenantContext $tenant,
        string $title,
        string $active,
        string $view,
        array $extra = [],
        int $status = 200,
    ): Response {
        $role = $tenant->role()->value();
        $variables = array_replace([
            'title' => $title,
            'metaTitle' => $title . ' | Terra Nova COS',
            'workspaceSection' => 'sales',
            'workspaceActive' => $active,
            'workspaceActiveSection' => $this->navigation->activeSection($active),
            'pageAssetEntries' => ['sales-workspace'],
            'csrfToken' => $this->csrf($request),
            'pageStatus' => null,
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => true,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ], $extra);

        return new Response(
            $this->renderer->render($request, $view, $variables),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function failure(Request $request, TenantContext $tenant, int $status, string $title, string $message): Response
    {
        return $this->render($request, $tenant, $title, 'sales-admin', 'error/failure', [
            'metaTitle' => $title . ' | Terra Nova',
            'metaRobots' => 'noindex,nofollow',
            'interfaceSurface' => 'workspace',
            'pageAssetEntries' => [],
            'workspaceSection' => null,
            'workspaceActive' => null,
            'failureCode' => $status,
            'failureTitle' => $title,
            'failureMessage' => $message,
            'failureRequestId' => 'TN-' . strtoupper(bin2hex(random_bytes(5))),
            'failureActionUrl' => '/sales/admin',
            'failureActionLabel' => 'До Sales Admin',
        ], $status);
    }

    private function csrf(Request $request): string
    {
        $sessionId = (string) $request->cookies->get($this->sessions->cookieName(), '');
        return $this->sessions->csrfToken($sessionId) ?? '';
    }
}
