<?php
declare(strict_types=1);

namespace App\Web\CapitalMarkets;

use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\DecisionWorkspaceReadService;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class DecisionWorkspacePageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CapitalMarketsAccessControlInterface $access,
        private DecisionWorkspaceReadService $workspace,
        private WorkspaceShellFactory $shells,
        private SessionCsrfValidator $csrf,
    ) {}

    public function overview(Request $request): Response
    {
        return $this->page($request, 'Capital Markets', 'overview', 'capital-markets-overview', CapitalMarketsCapability::View);
    }

    public function opportunities(Request $request): Response
    {
        return $this->page($request, 'Opportunity Board', 'opportunities', 'capital-markets-opportunities', CapitalMarketsCapability::OpportunityView);
    }

    public function opportunity(Request $request, string $id): Response
    {
        return $this->page($request, 'Opportunity', 'opportunity', 'capital-markets-opportunities', CapitalMarketsCapability::PortfolioView, $id);
    }

    public function markets(Request $request): Response
    {
        return $this->page($request, 'Market Explorer', 'markets', 'capital-markets-markets', CapitalMarketsCapability::MarketDataView);
    }

    public function research(Request $request): Response
    {
        return $this->page($request, 'Research', 'research', 'capital-markets-research', CapitalMarketsCapability::ResearchView);
    }

    public function hypothesis(Request $request, string $id): Response
    {
        return $this->page($request, 'Research Hypothesis', 'hypothesis', 'capital-markets-research', CapitalMarketsCapability::ResearchView, $id);
    }

    public function strategies(Request $request): Response
    {
        return $this->page($request, 'Strategies', 'strategies', 'capital-markets-strategies', CapitalMarketsCapability::ResearchView);
    }

    public function strategy(Request $request, string $id): Response
    {
        return $this->page($request, 'Strategy', 'strategy', 'capital-markets-strategies', CapitalMarketsCapability::ResearchView, $id);
    }

    public function portfolio(Request $request): Response
    {
        return $this->page($request, 'Portfolio Command Center', 'portfolio', 'capital-markets-portfolio', CapitalMarketsCapability::PortfolioView);
    }

    public function allocation(Request $request): Response
    {
        return $this->page($request, 'Allocation Workspace', 'allocation', 'capital-markets-allocation', CapitalMarketsCapability::AllocationView);
    }

    public function execution(Request $request): Response
    {
        return $this->page($request, 'Execution Cockpit', 'execution', 'capital-markets-execution', CapitalMarketsCapability::OpportunityView);
    }

    public function executionDetail(Request $request, string $id): Response
    {
        return $this->page($request, 'Execution', 'execution_detail', 'capital-markets-execution', CapitalMarketsCapability::OpportunityView, $id);
    }

    public function risk(Request $request): Response
    {
        return $this->page($request, 'Risk Center', 'risk', 'capital-markets-risk', CapitalMarketsCapability::RiskView);
    }

    public function performance(Request $request): Response
    {
        return $this->page($request, 'Performance Center', 'performance', 'capital-markets-performance', CapitalMarketsCapability::PortfolioView);
    }

    public function agents(Request $request): Response
    {
        return $this->page($request, 'Agent Center', 'agents', 'capital-markets-agents', CapitalMarketsCapability::View);
    }

    public function dataQuality(Request $request): Response
    {
        return $this->page($request, 'Data Quality Center', 'data_quality', 'capital-markets-data-quality', CapitalMarketsCapability::MarketDataQualityView);
    }

    private function page(
        Request $request,
        string $title,
        string $view,
        string $active,
        CapitalMarketsCapability $capability,
        ?string $id = null,
    ): Response {
        $tenant = $this->authorized($capability);
        if ($tenant instanceof Response) {
            return $tenant;
        }

        try {
            $organizationId = $tenant->organizationId()->value();
            $data = match ($view) {
                'overview' => $this->workspace->overview($organizationId),
                'opportunities' => $this->workspace->opportunities($organizationId),
                'opportunity' => $this->workspace->opportunity($organizationId, (string)$id),
                'markets' => $this->workspace->markets($organizationId),
                'research' => $this->workspace->research($organizationId),
                'hypothesis' => $this->workspace->hypothesis($organizationId, (string)$id),
                'strategies' => $this->workspace->strategies($organizationId),
                'strategy' => $this->workspace->strategy($organizationId, (string)$id),
                'portfolio' => $this->workspace->portfolio($organizationId),
                'allocation' => $this->workspace->allocation($organizationId),
                'execution' => $this->workspace->execution($organizationId),
                'execution_detail' => $this->workspace->executionDetail($organizationId, (string)$id),
                'risk' => $this->workspace->risk($organizationId),
                'performance' => $this->workspace->performance($organizationId),
                'agents' => $this->workspace->agents($organizationId),
                'data_quality' => $this->workspace->dataQuality($organizationId),
                default => throw new \LogicException('Unknown Capital Markets Decision Workspace view.'),
            };

            if (
                ($view === 'opportunity' && ($data['opportunity'] ?? []) === [])
                || ($view === 'hypothesis' && ($data['hypothesis'] ?? null) === null)
                || ($view === 'strategy' && ($data['strategy'] ?? null) === null)
                || ($view === 'execution_detail' && ($data['execution'] ?? []) === [])
            ) {
                return $this->render($request, $tenant, 'Capital Markets entity not found', $active, 'not_found', [
                    'workspace' => $data,
                    'entityType' => $view,
                    'entityId' => $id,
                ], 404);
            }

            return $this->render($request, $tenant, $title, $active, $view, ['workspace'=>$data]);
        } catch (Throwable $error) {
            error_log('capital_markets.decision_workspace.read_failed ['.$view.'] '.$error->getMessage());
            return $this->render($request, $tenant, 'Capital Markets unavailable', $active, 'failure', [
                'workspace' => [
                    'global' => [
                        'mode' => in_array($view, ['research','hypothesis','strategies','strategy'], true) ? 'RESEARCH' : 'PAPER',
                        'live_enabled' => false,
                        'risk_state' => 'UNAVAILABLE',
                        'data_health' => 'UNAVAILABLE',
                        'critical_alerts' => 1,
                        'alerts' => [[
                            'severity' => 'CRITICAL',
                            'message' => 'Decision Workspace read model is unavailable.',
                            'href' => '/capital-markets',
                        ]],
                    ],
                    'partial_errors' => [['source'=>$view, 'message'=>$error->getMessage()]],
                ],
                'failureMessage' => 'The requested decision view could not be composed from canonical Capital Markets data.',
            ], 503);
        }
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
        $organizationId = $tenant->organizationId()->value();
        $actorId = (int)$tenant->userId()->value();
        $variables = array_replace([
            'pageTitle' => $title,
            'cmView' => $view,
            'csrfToken' => $this->csrf->token($request),
            'query' => $request->query->all(),
            'permissions' => [
                'paper_execute' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::PaperExecute),
                'research_manage' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::ResearchManage),
                'research_run' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::ResearchExperimentRun),
                'strategy_manage' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::StrategyVersionManage),
                'strategy_promote' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::StrategyPromote),
                'risk_manage' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::RiskManagePolicy),
                'allocation_propose' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::AllocationPropose),
                'allocation_approve' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::AllocationApprove),
                'rebalance_propose' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::RebalancePropose),
                'rebalance_approve' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::RebalanceApprove),
                'market_data_manage' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::MarketDataManage),
            ],
        ], $extra);

        $context = new WebExtensionContext($organizationId, $tenant->role()->value(), 'workspace', 'capital-markets', $active);
        $breadcrumbs = [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Capital Markets', '/capital-markets'),
        ];
        if ($title !== 'Capital Markets') {
            $breadcrumbs[] = new ShellBreadcrumb($title);
        }
        $shell = $this->shells->create($tenant, $context, $title, $breadcrumbs);

        return new Response(
            $this->twig->render('experience/capital_markets/decision_workspace.html.twig', array_replace($variables, ['shell'=>$shell])),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    private function authorized(CapitalMarketsCapability $capability): TenantContext|Response
    {
        $tenant = $this->tenants->requireTenant();
        $organizationId = $tenant->organizationId()->value();

        if (!$this->modules->isEnabled($organizationId, 'capital_markets')) {
            return new Response('Capital Markets module is disabled.', 404);
        }

        $actorId = (int)$tenant->userId()->value();
        if (
            !$this->access->hasCapability($organizationId, $actorId, $capability->value)
            && !$this->access->hasCapability($organizationId, $actorId, CapitalMarketsCapability::Manage->value)
        ) {
            return new Response('Forbidden.', 403);
        }

        return $tenant;
    }

    private function allowed(string $organizationId, int $actorId, CapitalMarketsCapability $capability): bool
    {
        return $this->access->hasCapability($organizationId, $actorId, $capability->value)
            || $this->access->hasCapability($organizationId, $actorId, CapitalMarketsCapability::Manage->value);
    }
}
