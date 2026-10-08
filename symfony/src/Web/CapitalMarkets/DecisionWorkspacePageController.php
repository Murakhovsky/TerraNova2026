<?php
declare(strict_types=1);

namespace App\Web\CapitalMarkets;

use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use App\Web\Experience\Realtime\RealtimeTopicFactory;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\DecisionWorkspaceReadService;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
        private RealtimeTopicFactory $realtimeTopics,
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

    public function marketDetail(Request $request, string $id): Response
    {
        return $this->page($request, 'Market Detail', 'market_detail', 'capital-markets-markets', CapitalMarketsCapability::MarketDataView, $id);
    }

    public function relationshipDetail(Request $request, string $id): Response
    {
        return $this->page($request, 'Relationship Detail', 'relationship_detail', 'capital-markets-markets', CapitalMarketsCapability::RelationshipView, $id);
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

    public function export(Request $request, string $dataset, string $format): Response
    {
        $capability=match($dataset){
            'opportunities'=>CapitalMarketsCapability::OpportunityView,
            'performance'=>CapitalMarketsCapability::PortfolioView,
            'research-results'=>CapitalMarketsCapability::ResearchView,
            'executions'=>CapitalMarketsCapability::OpportunityView,
            default=>CapitalMarketsCapability::View,
        };
        $tenant=$this->authorized($capability);
        if($tenant instanceof Response)return $tenant;

        $organizationId=$tenant->organizationId()->value();
        $rows=match($dataset){
            'opportunities'=>$this->workspace->opportunities($organizationId,$request->query->all())['opportunities']??[],
            'research-results'=>$this->workspace->research($organizationId)['research']['results']??[],
            'executions'=>$this->workspace->execution($organizationId)['executions']??[],
            'performance'=>$this->performanceExportRows($this->workspace->performance($organizationId)),
            default=>[],
        };
        if(!in_array($dataset,['opportunities','performance','research-results','executions'],true)){
            return new JsonResponse(['error'=>['code'=>'UNSUPPORTED_DATASET']],404);
        }
        if($format==='json'){
            return new JsonResponse(
                ['dataset'=>$dataset,'data'=>$rows],
                200,
                ['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow'],
            );
        }
        if($format!=='csv'){
            return new JsonResponse(['error'=>['code'=>'UNSUPPORTED_FORMAT']],400);
        }

        $stream=fopen('php://temp','w+');
        if($stream===false)return new Response('Unable to create export.',500);
        $headers=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            foreach(array_keys($row) as $key){
                if(!in_array((string)$key,$headers,true))$headers[]=(string)$key;
            }
        }
        if($headers!==[])fputcsv($stream,$headers);
        foreach($rows as $row){
            if(!is_array($row))continue;
            $line=[];
            foreach($headers as $header){
                $value=$row[$header]??null;
                $line[]=is_array($value)||is_object($value)
                    ? json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
                    : $value;
            }
            fputcsv($stream,$line);
        }
        rewind($stream);
        $csv=stream_get_contents($stream);
        fclose($stream);

        return new Response($csv===false?'':$csv,200,[
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="capital-markets-'.$dataset.'.csv"',
            'Cache-Control'=>'no-store, private',
            'X-Robots-Tag'=>'noindex, nofollow',
        ]);
    }

    /** @param array<string,mixed> $page @return list<array<string,mixed>> */
    private function performanceExportRows(array $page): array
    {
        $attribution=$page['performance']['attribution']['by_strategy']??[];
        $rows=[];
        foreach($attribution as $strategy=>$row){
            if(!is_array($row))continue;
            $rows[]=array_replace(['strategy'=>(string)$strategy],$row);
        }
        return $rows;
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
                'opportunities' => $this->workspace->opportunities($organizationId, $request->query->all()),
                'opportunity' => $this->workspace->opportunity($organizationId, (string)$id),
                'markets' => $this->workspace->markets($organizationId),
                'market_detail' => $this->workspace->marketDetail(
                    $organizationId,
                    (string)$id,
                    $this->allowed($organizationId, (int)$tenant->userId()->value(), CapitalMarketsCapability::MarketDataHistoryView),
                ),
                'relationship_detail' => $this->workspace->relationshipDetail($organizationId, (string)$id),
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
                || ($view === 'market_detail' && ($data['instrument'] ?? null) === null)
                || ($view === 'relationship_detail' && ($data['relationship'] ?? null) === null)
            ) {
                return $this->render($request, $tenant, 'Capital Markets entity not found', $active, 'not_found', [
                    'workspace' => $data,
                    'entityType' => $view,
                    'entityId' => $id,
                ], 404);
            }

            return $this->render($request, $tenant, $title, $active, $view, ['workspace'=>$data, 'entityId'=>$id]);
        } catch (Throwable $error) {
            error_log('capital_markets.decision_workspace.read_failed ['.$view.'] '.$error->getMessage());
            return $this->render($request, $tenant, 'Capital Markets unavailable', $active, 'failure', [
                'workspace' => [
                    'global' => [
                        'mode' => in_array($view, ['research','hypothesis','strategies','strategy'], true) ? 'RESEARCH' : 'PAPER',
                        'live_enabled' => false,
                        'portfolio_equity' => null,
                        'available_capital' => null,
                        'deployed_capital' => null,
                        'reserved_capital' => null,
                        'net_pnl' => null,
                        'today_net_pnl' => null,
                        'pnl_30d' => null,
                        'risk_state' => 'UNAVAILABLE',
                        'data_health' => 'UNAVAILABLE',
                        'critical_alerts' => 1,
                        'alerts' => [[
                            'severity' => 'CRITICAL',
                            'code' => 'READ_MODEL_UNAVAILABLE',
                            'message' => 'Decision Workspace read model is unavailable.',
                            'href' => '/capital-markets',
                        ]],
                        'last_updated' => null,
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
            'realtimeTopic' => $this->realtimeTopics->workspace($organizationId, 'capital-markets'),
            'permissions' => [
                'view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::View),
                'opportunity_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::OpportunityView),
                'market_data_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::MarketDataView),
                'market_data_history_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::MarketDataHistoryView),
                'market_data_quality_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::MarketDataQualityView),
                'research_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::ResearchView),
                'portfolio_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::PortfolioView),
                'allocation_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::AllocationView),
                'risk_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::RiskView),
                'instrument_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::InstrumentView),
                'relationship_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::RelationshipView),
                'venue_view' => $this->allowed($organizationId, $actorId, CapitalMarketsCapability::VenueView),
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
        $entityId = isset($variables['entityId']) && is_scalar($variables['entityId'])
            ? trim((string)$variables['entityId'])
            : '';
        $detailParent = match ($view) {
            'opportunity' => ['Opportunities', '/capital-markets/opportunities'],
            'hypothesis' => ['Research', '/capital-markets/research'],
            'strategy' => ['Strategies', '/capital-markets/strategies'],
            'execution_detail' => ['Execution', '/capital-markets/execution'],
            default => null,
        };
        if (is_array($detailParent) && $entityId !== '') {
            $breadcrumbs[] = new ShellBreadcrumb($detailParent[0], $detailParent[1]);
            $breadcrumbs[] = new ShellBreadcrumb($entityId);
        } elseif ($title !== 'Capital Markets') {
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
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }

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
