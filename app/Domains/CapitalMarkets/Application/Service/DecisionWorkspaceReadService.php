<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsFoundationBoundary;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Query\ListInstruments;
use Domains\CapitalMarkets\Application\Query\ListRelationships;
use Domains\CapitalMarkets\Application\Query\ListVenues;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsPortfolioAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsResearchAgent;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Kernel\Agent\AgentRunProjection;
use Kernel\Agent\Contract\AgentRunReadModelInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Audit\Contract\ActivityHistoryRepositoryInterface;
use Platform\Audit\Model\ActivityHistoryEntry;
use Throwable;

/**
 * Read-only composition layer for CM-DECISION-WORKSPACE.
 *
 * Important: this service never recalculates canonical financial values.
 * Finance, risk and allocation stay authoritative in their existing engines.
 * This layer only composes, labels, ranks by existing portfolio priority and
 * exposes explicit unavailable/partial states to the UI.
 */
final readonly class DecisionWorkspaceReadService
{
    public function __construct(
        private CapitalRiskService $capitalRisk,
        private ResearchLabService $research,
        private MarketDataAdministrationService $marketData,
        private CapitalMarketsTradingRepositoryInterface $trading,
        private CapitalMarketsFoundationBoundary $foundation,
        private AgentRunReadModelInterface $agentRuns,
        private ActivityHistoryRepositoryInterface $activityHistory,
        private DomainModuleRegistry $domains,
    ) {}

    /**
     * @return list<array{id:string,label:string,path:string,kind:string,subtitle:string}>
     */
    public function searchEntities(string $organizationId): array
    {
        $errors = [];
        $instruments = $this->safe(
            fn(): array => $this->foundation->listInstruments(new ListInstruments($organizationId, ['status'=>'ACTIVE'], 1000)),
            [],
            $errors,
            'search_instruments',
        );
        $research = $this->safe(
            fn(): array => $this->research->workspace($organizationId),
            [],
            $errors,
            'search_research',
        );
        $opportunities = $this->safe(
            fn(): array => $this->trading->listOpportunities($organizationId, 1000),
            [],
            $errors,
            'search_opportunities',
        );
        $executions = $this->safe(
            fn(): array => $this->trading->listExecutions($organizationId, 1000),
            [],
            $errors,
            'search_executions',
        );

        $items = [];
        foreach ($instruments as $row) {
            if (!is_array($row)) continue;
            $id = (string)($row['id'] ?? $row['instrument_id'] ?? '');
            if ($id === '') continue;
            $label = (string)($row['symbol'] ?? $row['name'] ?? $row['asset_code'] ?? $id);
            $items[] = [
                'id' => 'capital_markets.instrument.'.$id,
                'label' => $label,
                'path' => '/capital-markets/instruments/'.rawurlencode($id),
                'kind' => 'instrument',
                'subtitle' => 'Instrument · '.$id,
            ];
        }

        foreach (array_merge(
            is_array($research['hypotheses'] ?? null) ? $research['hypotheses'] : [],
            is_array($research['rejections'] ?? null) ? $research['rejections'] : [],
        ) as $row) {
            if (!is_array($row)) continue;
            $id = (string)($row['hypothesis_id'] ?? $row['id'] ?? '');
            if ($id === '') continue;
            $label = (string)($row['title'] ?? $row['name'] ?? $row['hypothesis'] ?? $id);
            $items[] = [
                'id' => 'capital_markets.hypothesis.'.$id,
                'label' => $label,
                'path' => '/capital-markets/research/hypotheses/'.rawurlencode($id),
                'kind' => 'hypothesis',
                'subtitle' => 'Research Hypothesis · '.$id.' · '.strtoupper((string)($row['status'] ?? 'UNKNOWN')),
            ];
        }

        foreach ($research['strategy_versions'] ?? [] as $row) {
            if (!is_array($row)) continue;
            $id = (string)($row['strategy_version_id'] ?? $row['id'] ?? '');
            if ($id === '') continue;
            $label = (string)($row['name'] ?? $row['strategy_name'] ?? $row['strategy_id'] ?? $id);
            $items[] = [
                'id' => 'capital_markets.strategy.'.$id,
                'label' => $label,
                'path' => '/capital-markets/strategies/'.rawurlencode($id),
                'kind' => 'strategy',
                'subtitle' => 'Strategy · '.$id.' · '.strtoupper((string)($row['status'] ?? $row['stage'] ?? 'UNKNOWN')),
            ];
        }

        foreach ($opportunities as $row) {
            if (!is_array($row)) continue;
            $id = (string)($row['opportunity_id'] ?? $row['id'] ?? '');
            if ($id === '') continue;
            $label = (string)($row['name'] ?? $row['title'] ?? $row['hypothesis'] ?? $row['type'] ?? $id);
            $items[] = [
                'id' => 'capital_markets.opportunity.'.$id,
                'label' => $label,
                'path' => '/capital-markets/opportunities/'.rawurlencode($id),
                'kind' => 'opportunity',
                'subtitle' => 'Opportunity · '.$id.' · '.strtoupper((string)($row['status'] ?? 'UNKNOWN')),
            ];
        }

        foreach ($executions as $row) {
            if (!is_array($row)) continue;
            $id = (string)($row['execution_id'] ?? $row['id'] ?? '');
            if ($id === '') continue;
            $opportunityId = (string)($row['opportunity_id'] ?? '');
            $items[] = [
                'id' => 'capital_markets.execution.'.$id,
                'label' => $id,
                'path' => '/capital-markets/execution/'.rawurlencode($id),
                'kind' => 'execution',
                'subtitle' => 'Execution · '.strtoupper((string)($row['status'] ?? 'UNKNOWN')).($opportunityId !== '' ? ' · Opportunity '.$opportunityId : ''),
            ];
        }

        return $items;
    }

    /** @return array<string,mixed> */
    public function overview(string $organizationId): array
    {
        $errors = [];
        $core = $this->safe(
            fn(): array => $this->capitalRisk->workspace($organizationId),
            [],
            $errors,
            'portfolio',
        );
        $market = $this->safe(
            fn(): array => $this->marketData->dashboard($organizationId),
            ['sources'=>[], 'states'=>[], 'reference_states'=>[]],
            $errors,
            'market_data',
        );
        $global = $this->globalState($core, $market, 'PAPER');
        $opportunities = $this->opportunityRows($core);
        $strategies = $this->strategyAllocationRows($core);
        $actions = $this->recommendedActions($global, $opportunities, $core);

        return [
            'global' => $global,
            'capital' => $core['capital_state'] ?? [],
            'performance' => $core['performance'] ?? [],
            'risk' => $core['risk'] ?? [],
            'exposure' => $core['exposure'] ?? [],
            'top_opportunities' => array_slice($opportunities, 0, 5),
            'active_strategies' => array_slice($strategies, 0, 8),
            'material_risks' => $this->materialRisks($core),
            'recommended_actions' => $actions,
            'capital_map' => $this->capitalMap($core['capital_state'] ?? []),
            'partial_errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    public function opportunities(string $organizationId, array $filters = []): array
    {
        $errors = [];
        $core = $this->safe(
            fn(): array => $this->capitalRisk->workspace($organizationId),
            [],
            $errors,
            'portfolio',
        );
        $market = $this->safe(
            fn(): array => $this->marketData->dashboard($organizationId),
            ['sources'=>[], 'states'=>[], 'reference_states'=>[]],
            $errors,
            'market_data',
        );

        return [
            'global' => $this->globalState($core, $market, 'PAPER'),
            'opportunities' => $this->filterOpportunityRows($this->opportunityRows($core), $filters),
            'filters' => [
                'types' => ['Tokenized Spread','Cross-Venue Arbitrage','Spot / Perp Basis','Funding Capture','Cross-Venue Funding'],
                'decisions' => ['ACCEPT','ACCEPT_REDUCED_SIZE','HOLD','REJECT','REBALANCE_FIRST','MANUAL_REVIEW'],
                'saved_views' => ['Best Opportunities','Low Risk','High Capacity','Short TTL','Rejected by Portfolio'],
            ],
            'partial_errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    public function opportunity(string $organizationId, string $opportunityId): array
    {
        $errors = [];
        $detail = $this->safe(
            fn(): array => $this->capitalRisk->opportunityDetail($organizationId, $opportunityId),
            [],
            $errors,
            'opportunity',
        );
        $market = $this->safe(
            fn(): array => $this->marketData->dashboard($organizationId),
            ['sources'=>[], 'states'=>[], 'reference_states'=>[]],
            $errors,
            'market_data',
        );
        $core = [
            'capital_state' => $detail['portfolio_state'] ?? [],
            'risk' => $detail['risk'] ?? [],
            'exposure' => $detail['exposure'] ?? [],
            'opportunities' => isset($detail['opportunity']) ? [$detail['opportunity']] : [],
        ];
        $opportunity = $detail['opportunity'] ?? [];
        $execution = $detail['execution'] ?? [];
        $orders = [];
        $fills = [];
        if (is_array($execution) && $execution !== []) {
            $executionId = (string)($execution['execution_id'] ?? $execution['id'] ?? '');
            if ($executionId !== '') {
                $orders = $this->safe(
                    fn(): array => $this->trading->listPaperOrdersForExecution($organizationId, $executionId),
                    [],
                    $errors,
                    'execution_orders',
                );
                $fills = $this->safe(
                    fn(): array => $this->trading->listPaperFillsForExecution($organizationId, $executionId),
                    [],
                    $errors,
                    'execution_fills',
                );
            }
        }

        return array_replace($detail, [
            'global' => $this->globalState($core, $market, 'PAPER'),
            'economics' => $this->economics($opportunity),
            'evidence' => $this->opportunityEvidence($opportunity, $market),
            'decision_trace' => $this->decisionTrace($detail),
            'orders' => $orders,
            'fills' => $fills,
            'partial_errors' => $errors,
        ]);
    }

    /** @return array<string,mixed> */
    public function markets(string $organizationId): array
    {
        $errors = [];
        $market = $this->safe(
            fn(): array => $this->marketData->dashboard($organizationId),
            ['sources'=>[], 'states'=>[], 'reference_states'=>[]],
            $errors,
            'market_data',
        );
        $instruments = $this->safe(
            fn(): array => $this->foundation->listInstruments(new ListInstruments($organizationId, ['status'=>'ACTIVE'], 1000)),
            [],
            $errors,
            'instruments',
        );
        $relationships = $this->safe(
            fn(): array => $this->foundation->listRelationships(new ListRelationships($organizationId, 1000)),
            [],
            $errors,
            'relationships',
        );
        $venues = $this->safe(
            fn(): array => $this->foundation->listVenues(new ListVenues($organizationId, 500)),
            [],
            $errors,
            'venues',
        );
        $core = $this->safe(
            fn(): array => $this->capitalRisk->workspace($organizationId),
            [],
            $errors,
            'portfolio',
        );

        return [
            'global' => $this->globalState($core, $market, 'PAPER'),
            'states' => $market['states'] ?? [],
            'reference_states' => $market['reference_states'] ?? [],
            'sources' => $market['sources'] ?? [],
            'instruments' => $instruments,
            'relationships' => $relationships,
            'venues' => $venues,
            'market_rows' => $this->marketRows($market),
            'venue_rows' => $this->venueRows($market, $venues, $core),
            'partial_errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    public function research(string $organizationId): array
    {
        $errors = [];
        $research = $this->safe(fn(): array => $this->research->workspace($organizationId), [], $errors, 'research');
        $core = $this->safe(fn(): array => $this->capitalRisk->workspace($organizationId), [], $errors, 'portfolio');
        $market = $this->safe(
            fn(): array => $this->marketData->dashboard($organizationId),
            ['sources'=>[], 'states'=>[], 'reference_states'=>[]],
            $errors,
            'market_data',
        );

        return [
            'global' => $this->globalState($core, $market, 'RESEARCH'),
            'research' => $research,
            'pipeline' => $this->researchPipeline($research),
            'partial_errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    public function hypothesis(string $organizationId, string $hypothesisId): array
    {
        $page = $this->research($organizationId);
        $research = $page['research'] ?? [];
        $hypothesis = $this->findById($research['hypotheses'] ?? [], $hypothesisId, ['hypothesis_id','id']);
        if ($hypothesis === null) {
            $hypothesis = $this->findById($research['rejections'] ?? [], $hypothesisId, ['hypothesis_id','id']);
        }

        $page['hypothesis'] = $hypothesis;
        $page['experiments'] = array_values(array_filter(
            $research['experiments'] ?? [],
            static fn(array $row): bool => (string)($row['hypothesis_id'] ?? '') === $hypothesisId,
        ));
        $page['results'] = array_values(array_filter(
            $research['results'] ?? [],
            static fn(array $row): bool => (string)($row['hypothesis_id'] ?? '') === $hypothesisId,
        ));
        $page['decision_trace'] = $this->hypothesisTrace($hypothesisId, $research);
        $page['research_decision'] = $this->researchDecision($hypothesis);

        return $page;
    }

    /** @return array<string,mixed> */
    public function strategies(string $organizationId): array
    {
        $page = $this->research($organizationId);
        $research = $page['research'] ?? [];
        $core = $this->safe(fn(): array => $this->capitalRisk->workspace($organizationId), [], $page['partial_errors'], 'portfolio');
        $page['strategies'] = $this->strategyRows($research, $core);
        return $page;
    }

    /** @return array<string,mixed> */
    public function strategy(string $organizationId, string $strategyId): array
    {
        $page = $this->strategies($organizationId);
        $research = $page['research'] ?? [];
        $page['strategy'] = $this->findById($research['strategy_versions'] ?? [], $strategyId, ['strategy_version_id','id']);
        $page['scorecards'] = array_values(array_filter(
            $research['scorecards'] ?? [],
            static fn(array $row): bool => (string)($row['strategy_version_id'] ?? '') === $strategyId,
        ));
        $page['promotion_decisions'] = array_values(array_filter(
            $research['promotion_decisions'] ?? [],
            static fn(array $row): bool => (string)($row['strategy_version_id'] ?? '') === $strategyId,
        ));
        $page['experiments'] = array_values(array_filter(
            $research['experiments'] ?? [],
            static fn(array $row): bool => (string)($row['strategy_version_id'] ?? '') === $strategyId,
        ));
        $page['backtest_runs'] = $this->forStrategy($research['backtest_runs'] ?? [], $strategyId);
        $page['oos_runs'] = $this->forStrategy($research['oos_runs'] ?? [], $strategyId);
        $page['paper_runs'] = $this->forStrategy($research['paper_runs'] ?? [], $strategyId);
        return $page;
    }

    /** @return array<string,mixed> */
    public function portfolio(string $organizationId): array
    {
        $errors = [];
        $core = $this->safe(fn(): array => $this->capitalRisk->workspace($organizationId), [], $errors, 'portfolio');
        $market = $this->safe(
            fn(): array => $this->marketData->dashboard($organizationId),
            ['sources'=>[], 'states'=>[], 'reference_states'=>[]],
            $errors,
            'market_data',
        );
        return [
            'global' => $this->globalState($core, $market, 'PAPER'),
            'portfolio' => $core,
            'capital_map' => $this->capitalMap($core['capital_state'] ?? []),
            'strategy_allocations' => $this->strategyAllocationRows($core),
            'exposure_tabs' => $this->exposureTabs($core['exposure'] ?? []),
            'partial_errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    public function allocation(string $organizationId): array
    {
        $page = $this->portfolio($organizationId);
        $core = $page['portfolio'] ?? [];
        $plan = $core['allocation_plan'] ?? [];
        $page['allocation'] = [
            'plan' => $plan,
            'current' => $this->strategyAllocationRows($core),
            'recommendations' => $plan['allocations'] ?? [],
            'rebalance' => $core['rebalance'] ?? [],
            'simulation_opportunities' => $this->opportunityRows($core),
        ];
        return $page;
    }

    /** @return array<string,mixed> */
    public function execution(string $organizationId): array
    {
        $errors = [];
        $core = $this->safe(fn(): array => $this->capitalRisk->workspace($organizationId), [], $errors, 'portfolio');
        $market = $this->safe(
            fn(): array => $this->marketData->dashboard($organizationId),
            ['sources'=>[], 'states'=>[], 'reference_states'=>[]],
            $errors,
            'market_data',
        );
        $executions = $this->safe(
            fn(): array => $this->trading->listExecutions($organizationId, 500),
            [],
            $errors,
            'executions',
        );
        return [
            'global' => $this->globalState($core, $market, 'PAPER'),
            'executions' => $this->executionRows($executions, $core['opportunities'] ?? []),
            'partial_errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    public function executionDetail(string $organizationId, string $executionId): array
    {
        $page = $this->execution($organizationId);
        $errors = $page['partial_errors'] ?? [];
        $execution = $this->safe(
            fn(): array => $this->trading->getExecution($organizationId, $executionId) ?? [],
            [],
            $errors,
            'execution',
        );
        $orders = $this->safe(
            fn(): array => $this->trading->listPaperOrdersForExecution($organizationId, $executionId),
            [],
            $errors,
            'orders',
        );
        $fills = $this->safe(
            fn(): array => $this->trading->listPaperFillsForExecution($organizationId, $executionId),
            [],
            $errors,
            'fills',
        );
        $opportunityId = (string)($execution['opportunity_id'] ?? '');
        $opportunity = $opportunityId === ''
            ? []
            : ($this->safe(fn(): array => $this->trading->getOpportunity($organizationId, $opportunityId) ?? [], [], $errors, 'opportunity'));

        $page['execution'] = $execution;
        $page['orders'] = $orders;
        $page['fills'] = $fills;
        $page['execution_health'] = $this->executionHealth($execution, $orders, $fills);
        $page['opportunity'] = $opportunity;
        $page['partial_errors'] = $errors;
        return $page;
    }

    /** @return array<string,mixed> */
    public function risk(string $organizationId): array
    {
        $page = $this->portfolio($organizationId);
        $core = $page['portfolio'] ?? [];
        $page['risk'] = [
            'snapshot' => $core['risk'] ?? [],
            'envelope' => $core['risk_envelope'] ?? [],
            'stress_results' => $core['stress_results'] ?? [],
            'top_risks' => $this->materialRisks($core),
        ];
        return $page;
    }

    /** @return array<string,mixed> */
    public function performance(string $organizationId): array
    {
        $page = $this->portfolio($organizationId);
        $core = $page['portfolio'] ?? [];
        $errors = $page['partial_errors'] ?? [];
        $ledger = $this->safe(
            fn(): array => $this->trading->listLedgerTransactions($organizationId, 1000),
            [],
            $errors,
            'ledger',
        );
        $attribution = is_array($core['performance'] ?? null) ? $core['performance'] : [];
        $page['performance'] = [
            'attribution' => $attribution,
            'ledger' => $ledger,
            'edge_funnel' => $this->edgeFunnel($core['opportunities'] ?? [], $core['positions'] ?? []),
            'cost_breakdown' => $this->costBreakdown($attribution, $core['opportunities'] ?? []),
            'economics_status' => $attribution['economics_status'] ?? 'UNAVAILABLE',
            'economics_coverage' => $attribution['economics_coverage'] ?? '0/0',
            'economics_note' => $attribution['economics_note'] ?? 'Canonical execution economics are unavailable.',
            'time_window_note' => 'Today and 30D P&L remain unavailable until canonical time-window performance read models exist.',
        ];
        $page['partial_errors'] = $errors;
        return $page;
    }

    /** @return array<string,mixed> */
    public function agents(string $organizationId): array
    {
        $page = $this->portfolio($organizationId);
        $errors = $page['partial_errors'] ?? [];
        $allowed = [CapitalMarketsResearchAgent::NAME, CapitalMarketsPortfolioAgent::NAME];

        $projections = $this->safe(
            fn(): array => $this->agentRuns->recentForOrganization($organizationId, 100),
            [],
            $errors,
            'agent_runs',
        );
        $audit = $this->safe(
            fn(): array => $this->activityHistory->recent(OrganizationId::fromString($organizationId), 250),
            [],
            $errors,
            'agent_activity',
        );

        $runs = [];
        foreach ($projections as $projection) {
            if (!$projection instanceof AgentRunProjection || !in_array($projection->agentName, $allowed, true)) {
                continue;
            }

            $baseCorrelation = str_ends_with($projection->correlationId, ':final')
                ? substr($projection->correlationId, 0, -strlen(':final'))
                : $projection->correlationId;
            $tools = [];
            $auditCount = 0;
            foreach ($audit as $entry) {
                if (!$entry instanceof ActivityHistoryEntry) {
                    continue;
                }
                if ($entry->correlationId === $projection->correlationId || str_starts_with($entry->correlationId, $baseCorrelation.':')) {
                    $auditCount++;
                }
                if (
                    $entry->action === 'tool.execute'
                    && str_starts_with($entry->correlationId, $baseCorrelation.':tool:')
                ) {
                    $tools[] = [
                        'name' => $entry->resource->id,
                        'status' => strtoupper((string)($entry->metadata['status'] ?? 'RECORDED')),
                        'input' => $entry->input,
                        'output' => $entry->result,
                        'correlation_id' => $entry->correlationId,
                    ];
                }
            }

            $output = $projection->output;
            $profileKey = $projection->agentName === CapitalMarketsResearchAgent::NAME ? 'research' : 'portfolio';
            $evidence = is_array($output['evidence'][$profileKey] ?? null) ? $output['evidence'][$profileKey] : [];
            $requestedTools = [];
            foreach ($evidence['tool_requests'] ?? [] as $request) {
                if (is_array($request) && trim((string)($request['name'] ?? '')) !== '') {
                    $requestedTools[] = (string)$request['name'];
                }
            }

            $runs[] = [
                'id' => $projection->id,
                'agent_name' => $projection->agentName,
                'status' => $projection->status->value,
                'subject_type' => $projection->subjectType,
                'subject_id' => $projection->subjectId,
                'current_task' => trim(implode(' · ', array_filter([$projection->subjectType, $projection->subjectId]))) ?: '—',
                'decision' => $output['decision'] ?? null,
                'recommendation' => $output['reason'] ?? null,
                'confidence' => $projection->confidence,
                'findings' => is_array($evidence['findings'] ?? null) ? $evidence['findings'] : [],
                'limitations' => is_array($evidence['limitations'] ?? null) ? $evidence['limitations'] : [],
                'requested_tools' => array_values(array_unique($requestedTools)),
                'tools' => $tools,
                'output' => $output,
                'context_reference' => $projection->contextReference,
                'cost_amount' => $projection->costAmount,
                'cost_currency' => $projection->costCurrency,
                'input_tokens' => $projection->inputTokens,
                'output_tokens' => $projection->outputTokens,
                'duration_ms' => $projection->durationMs,
                'error' => $projection->error,
                'correlation_id' => $projection->correlationId,
                'audit_event_count' => $auditCount,
                'created_at' => $projection->createdAt->format(DATE_ATOM),
            ];
        }

        $definitions = [];
        foreach ($allowed as $name) {
            try {
                $definition = $this->domains->agent($name);
                $definitions[] = [
                    'name' => $definition->name,
                    'version' => $definition->version,
                    'profile' => $definition->profile,
                    'enabled' => $definition->enabled,
                    'authority' => 'RECOMMEND / PROPOSE',
                    'execution_mode' => $definition->defaultExecutionMode,
                    'max_actions_per_run' => $definition->maxActionsPerRun,
                ];
            } catch (Throwable $error) {
                $errors[] = ['source'=>'agent_definition', 'message'=>$error->getMessage()];
            }
        }

        $running = 0;
        $failed = 0;
        $toolCalls = 0;
        foreach ($runs as $run) {
            $status = strtoupper((string)($run['status'] ?? ''));
            if (in_array($status, ['RUNNING','STARTED'], true)) {
                $running++;
            }
            if ($status === 'FAILED') {
                $failed++;
            }
            $toolCalls += count($run['tools'] ?? []);
        }

        $page['agents'] = [
            'definitions' => $definitions,
            'runs' => $runs,
            'metrics' => [
                'registered' => count($definitions),
                'recent_runs' => count($runs),
                'running' => $running,
                'failed' => $failed,
                'tool_calls' => $toolCalls,
            ],
        ];
        $page['partial_errors'] = $errors;
        return $page;
    }

    /** @return array<string,mixed> */
    public function dataQuality(string $organizationId): array
    {
        $errors = [];
        $market = $this->safe(
            fn(): array => $this->marketData->dashboard($organizationId),
            ['sources'=>[], 'states'=>[], 'reference_states'=>[]],
            $errors,
            'market_data',
        );
        $core = $this->safe(fn(): array => $this->capitalRisk->workspace($organizationId), [], $errors, 'portfolio');
        $summary = $this->dataHealth($market);

        return [
            'global' => $this->globalState($core, $market, 'PAPER'),
            'summary' => $summary,
            'sources' => $market['sources'] ?? [],
            'states' => $market['states'] ?? [],
            'reference_states' => $market['reference_states'] ?? [],
            'quality_rows' => $this->qualityRows($market),
            'partial_errors' => $errors,
        ];
    }

    /** @param array<string,mixed> $market @return list<array<string,mixed>> */
    private function qualityRows(array $market): array
    {
        $referencesByInstrument = [];
        foreach ($market['reference_states'] ?? [] as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $instrumentId = (string)($reference['instrument_id'] ?? '');
            if ($instrumentId === '') {
                continue;
            }
            $existing = $referencesByInstrument[$instrumentId] ?? null;
            if (
                !is_array($existing)
                || strcmp((string)($reference['updated_at'] ?? ''), (string)($existing['updated_at'] ?? '')) > 0
            ) {
                $referencesByInstrument[$instrumentId] = $reference;
            }
        }

        $rows = [];
        $seenInstruments = [];
        foreach ($market['states'] ?? [] as $state) {
            if (!is_array($state)) {
                continue;
            }
            $instrumentId = (string)($state['instrument_id'] ?? '');
            if ($instrumentId === '') {
                continue;
            }

            $reference = is_array($referencesByInstrument[$instrumentId] ?? null)
                ? $referencesByInstrument[$instrumentId]
                : [];
            $referenceQuality = is_array($reference['quality'] ?? null) ? $reference['quality'] : [];
            $marketFlags = array_values(array_filter(
                is_array($state['quality_flags'] ?? null) ? $state['quality_flags'] : [],
                static fn(mixed $flag): bool => is_scalar($flag) && trim((string)$flag) !== '',
            ));
            $referenceFlags = array_values(array_filter(
                is_array($referenceQuality['flags'] ?? null) ? $referenceQuality['flags'] : [],
                static fn(mixed $flag): bool => is_scalar($flag) && trim((string)$flag) !== '',
            ));
            $reasons = [];
            foreach ($marketFlags as $flag) {
                $reasons[] = 'Market: '.(string)$flag;
            }
            foreach ($referenceFlags as $flag) {
                $reasons[] = 'Reference: '.(string)$flag;
            }

            $rows[] = [
                'instrument_id' => $instrumentId,
                'venue_id' => $state['venue_id'] ?? null,
                'source_id' => $state['source_id'] ?? null,
                'quote_age_ms' => is_array($state['best_quote'] ?? null) ? ($state['latency']['event_age_ms'] ?? null) : null,
                // Canonical MarketOrderBook does not store an independent observation timestamp yet.
                // Showing the MarketState age as book age would falsely imply freshness we do not possess.
                'book_age_ms' => null,
                'reference_age_ms' => $reference['reference_age_ms'] ?? null,
                'sequence' => $state['last_sequence'] ?? null,
                'book_sequence' => is_array($state['order_book'] ?? null) ? ($state['order_book']['sequence'] ?? null) : null,
                'status' => strtoupper((string)($state['market_status'] ?? 'UNKNOWN')),
                'trust' => strtoupper((string)($state['trust_status'] ?? $state['quality_status'] ?? 'UNAVAILABLE')),
                'reference_trust' => strtoupper((string)($referenceQuality['status'] ?? 'UNAVAILABLE')),
                'mode' => strtoupper((string)($state['mode'] ?? 'HISTORICAL')),
                'updated_at' => $state['updated_at'] ?? null,
                'reference_updated_at' => $reference['updated_at'] ?? null,
                'reasons' => array_values(array_unique($reasons)),
                'book_age_note' => is_array($state['order_book'] ?? null)
                    ? 'Order book exists, but canonical MarketOrderBook has no independent observation timestamp.'
                    : 'No canonical order book is present for this market state.',
            ];
            $seenInstruments[$instrumentId] = true;
        }

        foreach ($referencesByInstrument as $instrumentId => $reference) {
            if (isset($seenInstruments[$instrumentId])) {
                continue;
            }
            $referenceQuality = is_array($reference['quality'] ?? null) ? $reference['quality'] : [];
            $referenceFlags = array_values(array_filter(
                is_array($referenceQuality['flags'] ?? null) ? $referenceQuality['flags'] : [],
                static fn(mixed $flag): bool => is_scalar($flag) && trim((string)$flag) !== '',
            ));
            $rows[] = [
                'instrument_id' => $instrumentId,
                'venue_id' => null,
                'source_id' => $reference['source_id'] ?? null,
                'quote_age_ms' => null,
                'book_age_ms' => null,
                'reference_age_ms' => $reference['reference_age_ms'] ?? null,
                'sequence' => $reference['last_sequence'] ?? null,
                'book_sequence' => null,
                'status' => strtoupper((string)($reference['session'] ?? 'REFERENCE_ONLY')),
                'trust' => 'REFERENCE_ONLY',
                'reference_trust' => strtoupper((string)($referenceQuality['status'] ?? 'UNAVAILABLE')),
                'mode' => strtoupper((string)($reference['mode'] ?? 'HISTORICAL')),
                'updated_at' => null,
                'reference_updated_at' => $reference['updated_at'] ?? null,
                'reasons' => array_map(static fn(mixed $flag): string => 'Reference: '.(string)$flag, $referenceFlags),
                'book_age_note' => 'Reference-only state has no canonical order book.',
            ];
        }

        return $rows;
    }

    /** @param array<string,mixed> $core @param array<string,mixed> $market @return array<string,mixed> */
    private function globalState(array $core, array $market, string $mode): array
    {
        $capital = $core['capital_state'] ?? [];
        $performance = $core['performance'] ?? [];
        $risk = $core['risk'] ?? [];
        $data = $this->dataHealth($market);
        $alerts = [];

        $riskState = strtoupper((string)($risk['status'] ?? 'NOT_CALCULATED'));
        if (!in_array($riskState, ['NORMAL','NOT_CALCULATED',''], true)) {
            $alerts[] = [
                'severity' => in_array($riskState, ['HALTED','EMERGENCY','REDUCE_ONLY'], true) ? 'CRITICAL' : 'HIGH',
                'code' => 'RISK_STATE',
                'message' => 'Portfolio risk state is '.$riskState.'.',
                'href' => '/capital-markets/risk',
            ];
        }
        if (($data['status'] ?? 'UNAVAILABLE') !== 'HEALTHY') {
            $alerts[] = [
                'severity' => ($data['status'] ?? '') === 'UNAVAILABLE' ? 'CRITICAL' : 'WARNING',
                'code' => 'DATA_HEALTH',
                'message' => 'Market data health is '.($data['status'] ?? 'UNAVAILABLE').'.',
                'href' => '/capital-markets/data-quality',
            ];
        }
        $unknown = $core['exposure']['unknown_exposure'] ?? [];
        if (is_array($unknown) && $unknown !== []) {
            $alerts[] = [
                'severity' => 'WARNING',
                'code' => 'UNKNOWN_EXPOSURE',
                'message' => count($unknown).' position(s) are excluded from economic netting.',
                'href' => '/capital-markets/portfolio',
            ];
        }

        return [
            'mode' => $mode,
            'live_enabled' => false,
            'portfolio_equity' => $capital['total'] ?? null,
            'available_capital' => $capital['available'] ?? null,
            'deployed_capital' => $capital['deployed'] ?? null,
            'reserved_capital' => $capital['reserved'] ?? null,
            'net_pnl' => $performance['net_pnl'] ?? null,
            'today_net_pnl' => null,
            'pnl_30d' => null,
            'risk_state' => $riskState === '' ? 'NOT_CALCULATED' : $riskState,
            'data_health' => $data['status'],
            'critical_alerts' => count(array_filter(
                $alerts,
                static fn(array $row): bool => in_array($row['severity'] ?? '', ['HIGH','CRITICAL'], true),
            )),
            'alerts' => $alerts,
            'last_updated' => $capital['timestamp'] ?? $data['last_updated'] ?? null,
        ];
    }

    /** @param array<string,mixed> $market @return array<string,mixed> */
    private function dataHealth(array $market): array
    {
        $sources = is_array($market['sources'] ?? null) ? $market['sources'] : [];
        $states = is_array($market['states'] ?? null) ? $market['states'] : [];
        $references = is_array($market['reference_states'] ?? null) ? $market['reference_states'] : [];

        $trusted = 0;
        $stale = 0;
        $unavailable = 0;
        $degraded = 0;
        $lastUpdated = null;
        foreach (array_merge($states, $references) as $state) {
            if (!is_array($state)) {
                continue;
            }
            $status = strtoupper((string)($state['trust_status'] ?? $state['quality_status'] ?? $state['quality']['status'] ?? 'UNAVAILABLE'));
            if ($status === 'TRUSTED') {
                $trusted++;
            } elseif ($status === 'STALE') {
                $stale++;
            } elseif ($status === 'DEGRADED') {
                $degraded++;
            } else {
                $unavailable++;
            }
            $updated = (string)($state['updated_at'] ?? '');
            if ($updated !== '' && ($lastUpdated === null || strcmp($updated, $lastUpdated) > 0)) {
                $lastUpdated = $updated;
            }
        }

        $sourceOnline = 0;
        $sourceProblems = 0;
        $latencies = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $health = is_array($source['health'] ?? null) ? $source['health'] : [];
            $connection = strtoupper((string)($health['connection_state'] ?? ($source['enabled'] ?? false ? 'UNKNOWN' : 'DISABLED')));
            if (in_array($connection, ['CONNECTED','ONLINE','READY','HEALTHY'], true)) {
                $sourceOnline++;
            } elseif (($source['enabled'] ?? false) === true) {
                $sourceProblems++;
            }
            if (isset($health['last_latency_ms']) && is_numeric($health['last_latency_ms'])) {
                $latencies[] = (int)$health['last_latency_ms'];
            }
        }

        $status = 'HEALTHY';
        if ($sources === [] && $states === [] && $references === []) {
            $status = 'UNAVAILABLE';
        } elseif ($unavailable > 0 || $sourceProblems > 0) {
            $status = 'DEGRADED';
        } elseif ($stale > 0) {
            $status = 'STALE';
        } elseif ($degraded > 0) {
            $status = 'DEGRADED';
        }

        $avgLatency = $latencies === [] ? null : intdiv(array_sum($latencies), count($latencies));
        return [
            'status' => $status,
            'sources_online' => $sourceOnline,
            'sources_total' => count($sources),
            'trusted_markets' => $trusted,
            'stale_markets' => $stale,
            'degraded_markets' => $degraded,
            'unavailable_markets' => $unavailable,
            'data_gaps' => $unavailable,
            'avg_latency_ms' => $avgLatency,
            'critical_issues' => $sourceProblems + $unavailable,
            'last_updated' => $lastUpdated,
        ];
    }

    /** @param array<string,mixed> $core @return list<array<string,mixed>> */
    private function opportunityRows(array $core): array
    {
        $plan = is_array($core['allocation_plan'] ?? null) ? $core['allocation_plan'] : [];
        $allocations = [];
        foreach ($plan['allocations'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string)($item['opportunity_id'] ?? '');
            if ($id !== '') {
                $allocations[$id] = $item;
            }
        }

        $rows = [];
        foreach ($core['opportunities'] ?? [] as $opportunity) {
            if (!is_array($opportunity)) {
                continue;
            }
            $id = (string)($opportunity['opportunity_id'] ?? $opportunity['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $allocation = $allocations[$id] ?? [];
            $rows[] = [
                'id' => $id,
                'opportunity' => (string)($opportunity['name'] ?? $opportunity['title'] ?? $opportunity['hypothesis'] ?? $opportunity['type'] ?? 'Opportunity'),
                'type' => (string)($opportunity['opportunity_type'] ?? $opportunity['type'] ?? $opportunity['hypothesis'] ?? 'UNKNOWN'),
                'instruments' => $this->strings($opportunity, ['instrument_id','instrument_ids','buy_instrument_id','sell_instrument_id','spot_instrument_id','perpetual_instrument_id']),
                'venues' => $this->strings($opportunity, ['venue_id','venue_ids','buy_venue_id','sell_venue_id','spot_venue_id','perpetual_venue_id']),
                'expected_net' => $opportunity['expected_net_pnl'] ?? $opportunity['expected_net'] ?? $opportunity['net_pnl'] ?? null,
                'expected_return' => $opportunity['expected_return'] ?? $opportunity['net_return'] ?? $opportunity['return'] ?? null,
                'capital' => $opportunity['required_capital'] ?? $opportunity['capital_required'] ?? $opportunity['expected_capital'] ?? null,
                'approved_capital' => $allocation['approved_capital'] ?? null,
                'capacity' => $opportunity['capacity'] ?? $opportunity['maximum_capacity'] ?? null,
                'risk' => strtoupper((string)($opportunity['risk_level'] ?? $opportunity['risk'] ?? 'UNASSESSED')),
                'portfolio_impact' => $this->portfolioImpactLabel($allocation),
                'ttl' => $opportunity['ttl'] ?? $opportunity['ttl_seconds'] ?? $opportunity['expires_at'] ?? null,
                'status' => strtoupper((string)($opportunity['status'] ?? 'UNKNOWN')),
                'decision' => strtoupper((string)($allocation['decision'] ?? 'NOT_ALLOCATED')),
                'priority' => isset($allocation['priority']) ? (int)$allocation['priority'] : 999999,
                'reason' => (string)($allocation['reason'] ?? ''),
                'raw' => $opportunity,
            ];
        }

        usort($rows, static function(array $a, array $b): int {
            $priority = ($a['priority'] ?? 999999) <=> ($b['priority'] ?? 999999);
            if ($priority !== 0) {
                return $priority;
            }
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function filterOpportunityRows(array $rows, array $filters): array
    {
        $view=strtolower(trim((string)($filters['view']??'')));
        $type=strtoupper(trim((string)($filters['type']??'')));
        $risk=strtoupper(trim((string)($filters['risk']??'')));
        $status=strtoupper(trim((string)($filters['status']??'')));
        $decision=strtoupper(trim((string)($filters['decision']??'')));
        $strategy=trim((string)($filters['strategy']??''));
        $instrument=trim((string)($filters['instrument']??''));
        $venue=trim((string)($filters['venue']??''));
        $minNet=trim((string)($filters['min_net']??''));
        $minReturn=trim((string)($filters['min_return']??''));
        $maxCapital=trim((string)($filters['max_capital']??''));

        $filtered = array_values(array_filter($rows, function(array $row) use(
            $view,$type,$risk,$status,$decision,$strategy,$instrument,$venue,$minNet,$minReturn,$maxCapital
        ):bool{
            if($view==='low-risk' && !in_array(strtoupper((string)($row['risk']??'')),['LOW','MINIMAL'],true))return false;
            if($view==='rejected' && !in_array(strtoupper((string)($row['decision']??'')),['REJECT','REBALANCE_FIRST'],true))return false;
            if($view==='short-ttl'){
                $ttl=$row['ttl']??null;
                if(!is_numeric($ttl)||(int)$ttl>3600)return false;
            }
            if($view==='high-capacity' && ($row['capacity']??null)===null)return false;
            if($type!=='' && strtoupper((string)($row['type']??''))!==$type)return false;
            if($risk!=='' && strtoupper((string)($row['risk']??''))!==$risk)return false;
            if($status!=='' && strtoupper((string)($row['status']??''))!==$status)return false;
            if($decision!=='' && strtoupper((string)($row['decision']??''))!==$decision)return false;
            if($strategy!=='' && !str_contains(strtoupper(json_encode($row['raw']??[],JSON_UNESCAPED_SLASHES)?:''),strtoupper($strategy)))return false;
            if($instrument!=='' && !in_array($instrument,$row['instruments']??[],true))return false;
            if($venue!=='' && !in_array($venue,$row['venues']??[],true))return false;
            if($minNet!=='' && !$this->decimalAtLeast($row['expected_net']??null,$minNet))return false;
            if($minReturn!=='' && !$this->decimalAtLeast($row['expected_return']??null,$minReturn))return false;
            if($maxCapital!=='' && !$this->decimalAtMost($row['capital']??null,$maxCapital))return false;
            return true;
        }));

        if ($view === 'high-capacity') {
            usort($filtered, function(array $left, array $right): int {
                return $this->decimalCompareNullable($right['capacity'] ?? null, $left['capacity'] ?? null);
            });
        }

        return $filtered;
    }

    private function decimalCompareNullable(mixed $left, mixed $right): int
    {
        if (!is_scalar($left) || !is_numeric((string)$left)) {
            return (!is_scalar($right) || !is_numeric((string)$right)) ? 0 : -1;
        }
        if (!is_scalar($right) || !is_numeric((string)$right)) {
            return 1;
        }
        try {
            return Decimal::fromString((string)$left)->compareTo(Decimal::fromString((string)$right));
        } catch (Throwable) {
            return 0;
        }
    }

    private function decimalAtLeast(mixed $value,string $minimum):bool
    {
        if(!is_scalar($value)||!is_numeric((string)$value)||!is_numeric($minimum))return false;
        try{return Decimal::fromString((string)$value)->compareTo(Decimal::fromString($minimum))>=0;}
        catch(Throwable){return false;}
    }

    private function decimalAtMost(mixed $value,string $maximum):bool
    {
        if(!is_scalar($value)||!is_numeric((string)$value)||!is_numeric($maximum))return false;
        try{return Decimal::fromString((string)$value)->compareTo(Decimal::fromString($maximum))<=0;}
        catch(Throwable){return false;}
    }

    /** @param array<string,mixed> $allocation */
    private function portfolioImpactLabel(array $allocation): string
    {
        $decision = strtoupper((string)($allocation['decision'] ?? ''));
        $reason = strtoupper((string)($allocation['reason'] ?? ''));
        if ($decision === 'ACCEPT') {
            return 'POSITIVE';
        }
        if ($decision === 'ACCEPT_REDUCED_SIZE') {
            return str_contains($reason, 'CONCENTRATION') ? 'CONCENTRATION' : 'RISK_LIMIT';
        }
        if ($decision === 'REJECT') {
            if (str_contains($reason, 'CAPITAL')) {
                return 'CAPITAL_CONFLICT';
            }
            return 'RISK_LIMIT';
        }
        return 'NEUTRAL';
    }

    /** @param array<string,mixed> $core @return list<array<string,mixed>> */
    private function strategyAllocationRows(array $core): array
    {
        $performance = is_array($core['performance']['by_strategy'] ?? null) ? $core['performance']['by_strategy'] : [];
        $rows = [];
        foreach ($core['strategy_allocations'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)($row['strategy_version_id'] ?? $row['strategy_id'] ?? 'UNKNOWN');
            $perf = is_array($performance[$id] ?? null) ? $performance[$id] : [];
            $rows[] = array_replace($row, [
                'strategy_version_id' => $id,
                'net_pnl' => $perf['pnl'] ?? null,
                'risk_used' => $perf['risk_consumed'] ?? null,
            ]);
        }
        return $rows;
    }

    /** @param array<string,mixed> $research @param array<string,mixed> $core @return list<array<string,mixed>> */
    private function strategyRows(array $research, array $core): array
    {
        $scorecardByStrategy = [];
        foreach ($research['scorecards'] ?? [] as $scorecard) {
            if (!is_array($scorecard)) {
                continue;
            }
            $id = (string)($scorecard['strategy_version_id'] ?? '');
            if ($id !== '') {
                $scorecardByStrategy[$id] = $scorecard;
            }
        }
        $allocationByStrategy = [];
        foreach ($this->strategyAllocationRows($core) as $allocation) {
            $allocationByStrategy[(string)($allocation['strategy_version_id'] ?? '')] = $allocation;
        }
        $promotionByStrategy = [];
        foreach ($research['promotion_decisions'] ?? [] as $promotion) {
            if (!is_array($promotion)) {
                continue;
            }
            $id = (string)($promotion['strategy_version_id'] ?? '');
            if ($id !== '') {
                $promotionByStrategy[$id] = $promotion;
            }
        }

        $rows = [];
        foreach ($research['strategy_versions'] ?? [] as $strategy) {
            if (!is_array($strategy)) {
                continue;
            }
            $id = (string)($strategy['strategy_version_id'] ?? $strategy['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $allocation = $allocationByStrategy[$id] ?? [];
            $scorecard = $scorecardByStrategy[$id] ?? [];
            $promotion = $promotionByStrategy[$id] ?? [];
            $rows[] = [
                'id' => $id,
                'strategy' => (string)($strategy['name'] ?? $strategy['strategy_name'] ?? $strategy['strategy_id'] ?? $id),
                'version' => $strategy['version'] ?? $strategy['version_number'] ?? null,
                'mode' => strtoupper((string)($strategy['mode'] ?? $strategy['stage'] ?? $promotion['to_stage'] ?? 'RESEARCH')),
                'capital' => $allocation['allocated_capital'] ?? null,
                'net_pnl' => $allocation['net_pnl'] ?? null,
                'drawdown' => $scorecard['drawdown'] ?? null,
                'score' => $scorecard['score'] ?? $scorecard['overall_score'] ?? null,
                'capacity' => $scorecard['capacity'] ?? null,
                'status' => strtoupper((string)($strategy['status'] ?? 'UNKNOWN')),
                'decision' => strtoupper((string)($promotion['status'] ?? 'NONE')),
                'raw' => $strategy,
                'scorecard' => $scorecard,
                'promotion' => $promotion,
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $market @return list<array<string,mixed>> */
    private function marketRows(array $market): array
    {
        $rows = [];
        foreach ($market['states'] ?? [] as $state) {
            if (!is_array($state)) {
                continue;
            }
            $quote = is_array($state['best_quote'] ?? null) ? $state['best_quote'] : [];
            $rows[] = [
                'instrument_id' => $state['instrument_id'] ?? null,
                'venue_id' => $state['venue_id'] ?? null,
                'bid' => $quote['bid_price']['value'] ?? null,
                'ask' => $quote['ask_price']['value'] ?? null,
                'mid' => $state['mid_price'] ?? $quote['mid_price'] ?? null,
                'spread_bps' => $state['spread_bps'] ?? $quote['spread_bps'] ?? null,
                'volume' => $state['volume'] ?? null,
                'liquidity' => $state['order_book']['depth'] ?? null,
                'age_ms' => $state['latency']['event_age_ms'] ?? null,
                'trust' => strtoupper((string)($state['trust_status'] ?? $state['quality_status'] ?? 'UNAVAILABLE')),
                'mode' => strtoupper((string)($state['mode'] ?? 'HISTORICAL')),
                'last_updated' => $state['updated_at'] ?? null,
                'market_status' => $state['market_status'] ?? null,
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $market @param list<array<string,mixed>> $venues @param array<string,mixed> $core @return list<array<string,mixed>> */
    private function venueRows(array $market, array $venues, array $core): array
    {
        $statesByVenue = [];
        foreach ($market['states'] ?? [] as $state) {
            if (!is_array($state)) {
                continue;
            }
            $venue = (string)($state['venue_id'] ?? '');
            if ($venue === '') {
                continue;
            }
            $statesByVenue[$venue][] = $state;
        }
        $capitalByVenue = [];
        foreach ($core['capital_state']['by_location'] ?? [] as $location) {
            if (!is_array($location)) {
                continue;
            }
            $venue = (string)($location['venue_id'] ?? '');
            if ($venue === '') {
                continue;
            }
            $capitalByVenue[$venue][] = $location;
        }
        $riskByVenue = is_array($core['exposure']['by_venue'] ?? null) ? $core['exposure']['by_venue'] : [];

        $rows = [];
        foreach ($venues as $venue) {
            $id = (string)($venue['id'] ?? $venue['venue_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $states = $statesByVenue[$id] ?? [];
            $trusted = count(array_filter(
                $states,
                static fn(array $state): bool => strtoupper((string)($state['trust_status'] ?? $state['quality_status'] ?? '')) === 'TRUSTED',
            ));
            $rows[] = [
                'venue' => $venue,
                'market_count' => count($states),
                'trusted_markets' => $trusted,
                'data_health' => $states === [] ? 'UNAVAILABLE' : ($trusted === count($states) ? 'HEALTHY' : 'DEGRADED'),
                'capital_locations' => $capitalByVenue[$id] ?? [],
                'exposure' => $riskByVenue[$id] ?? null,
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $core @return list<array<string,mixed>> */
    private function materialRisks(array $core): array
    {
        $risk = is_array($core['risk'] ?? null) ? $core['risk'] : [];
        $items = [];
        foreach ($risk['risk_limit_utilization'] ?? [] as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $utilization = $row['utilization'] ?? null;
            $breached = ($row['breached'] ?? false) === true;
            $interesting = $breached || (is_numeric($utilization) && (int)$utilization >= 80);
            if (!$interesting) {
                continue;
            }
            $items[] = [
                'metric' => $row['metric'] ?? $key,
                'current' => $row['current'] ?? null,
                'limit' => $row['limit'] ?? null,
                'utilization' => $utilization,
                'state' => $breached ? 'BREACHED' : 'CAUTION',
            ];
        }
        if (isset($core['exposure']['unknown_exposure']) && is_array($core['exposure']['unknown_exposure']) && $core['exposure']['unknown_exposure'] !== []) {
            $items[] = [
                'metric' => 'Unknown economic exposure',
                'current' => count($core['exposure']['unknown_exposure']),
                'limit' => 0,
                'utilization' => null,
                'state' => 'CAUTION',
            ];
        }
        return array_slice($items, 0, 8);
    }

    /** @param array<string,mixed> $global @param list<array<string,mixed>> $opportunities @param array<string,mixed> $core @return list<array<string,mixed>> */
    private function recommendedActions(array $global, array $opportunities, array $core): array
    {
        $actions = [];
        foreach ($global['alerts'] ?? [] as $alert) {
            $actions[] = [
                'priority' => $alert['severity'] ?? 'WARNING',
                'label' => $alert['message'] ?? 'Review alert',
                'href' => $alert['href'] ?? '/capital-markets',
                'reason' => $alert['code'] ?? 'SYSTEM_ALERT',
            ];
        }
        foreach ($opportunities as $opportunity) {
            if (in_array($opportunity['decision'] ?? '', ['ACCEPT','ACCEPT_REDUCED_SIZE'], true)) {
                $actions[] = [
                    'priority' => 'INFO',
                    'label' => 'Review '.$opportunity['opportunity'],
                    'href' => '/capital-markets/opportunities/'.rawurlencode((string)$opportunity['id']),
                    'reason' => $opportunity['decision'],
                ];
                break;
            }
        }
        if (($core['rebalance']['status'] ?? null) !== null && !in_array(strtoupper((string)$core['rebalance']['status']), ['NONE','NO_ACTION'], true)) {
            $actions[] = [
                'priority' => 'WARNING',
                'label' => 'Review portfolio rebalance recommendation',
                'href' => '/capital-markets/allocation',
                'reason' => (string)($core['rebalance']['reason'] ?? 'REBALANCE'),
            ];
        }
        return array_slice($actions, 0, 5);
    }

    /** @param array<string,mixed> $capital @return list<array<string,mixed>> */
    private function capitalMap(array $capital): array
    {
        return [
            ['key'=>'AVAILABLE','value'=>$capital['available'] ?? null],
            ['key'=>'RESERVED','value'=>$capital['reserved'] ?? null],
            ['key'=>'DEPLOYED','value'=>$capital['deployed'] ?? null],
            ['key'=>'MARGIN','value'=>$capital['margined'] ?? null],
            ['key'=>'SETTLEMENT','value'=>$capital['unsettled'] ?? null],
        ];
    }

    /** @param array<string,mixed> $exposure @return array<string,array<string,mixed>> */
    private function exposureTabs(array $exposure): array
    {
        return [
            'underlying' => is_array($exposure['by_underlying'] ?? null) ? $exposure['by_underlying'] : [],
            'asset' => is_array($exposure['by_asset'] ?? null) ? $exposure['by_asset'] : [],
            'venue' => is_array($exposure['by_venue'] ?? null) ? $exposure['by_venue'] : [],
            'strategy' => is_array($exposure['by_strategy'] ?? null) ? $exposure['by_strategy'] : [],
            'currency' => is_array($exposure['by_currency'] ?? null) ? $exposure['by_currency'] : [],
            'counterparty' => is_array($exposure['by_counterparty'] ?? null) ? $exposure['by_counterparty'] : [],
            'chain' => is_array($exposure['by_chain'] ?? null) ? $exposure['by_chain'] : [],
        ];
    }

    /** @param list<array<string,mixed>> $executions @param list<array<string,mixed>> $opportunities @return list<array<string,mixed>> */
    private function executionRows(array $executions, array $opportunities): array
    {
        $byId = [];
        foreach ($opportunities as $opportunity) {
            if (!is_array($opportunity)) {
                continue;
            }
            $id = (string)($opportunity['opportunity_id'] ?? $opportunity['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $opportunity;
            }
        }
        $rows = [];
        foreach ($executions as $execution) {
            if (!is_array($execution)) {
                continue;
            }
            $id = (string)($execution['execution_id'] ?? $execution['id'] ?? '');
            $opportunityId = (string)($execution['opportunity_id'] ?? '');
            $opportunity = $byId[$opportunityId] ?? [];
            $rows[] = [
                'id' => $id,
                'strategy' => $execution['strategy_id'] ?? $opportunity['strategy_id'] ?? $opportunity['strategy_version_id'] ?? null,
                'opportunity_id' => $opportunityId,
                'legs' => $execution['legs'] ?? $execution['plan']['legs'] ?? [],
                'capital' => $execution['capital'] ?? $opportunity['required_capital'] ?? $opportunity['capital_required'] ?? null,
                'state' => strtoupper((string)($execution['status'] ?? 'UNKNOWN')),
                'hedge' => $this->hedgeStateFromExecution($execution),
                'duration' => $execution['duration'] ?? null,
                'pnl' => $execution['realized_pnl'] ?? $execution['pnl'] ?? null,
                'updated_at' => $execution['updated_at'] ?? $execution['created_at'] ?? null,
                'raw' => $execution,
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $execution @param list<array<string,mixed>> $orders @param list<array<string,mixed>> $fills @return array<string,mixed> */
    private function executionHealth(array $execution, array $orders, array $fills): array
    {
        $status = strtoupper((string)($execution['status'] ?? 'UNKNOWN'));
        $hedge = $this->hedgeStateFromExecution($execution);
        return [
            'status' => $status,
            'hedge_state' => $hedge,
            'severity' => in_array($status, ['PARTIALLY_EXECUTED','COMPENSATING','FAILED'], true) ? 'HIGH' : 'NORMAL',
            'checkpoint' => $execution['checkpoint'] ?? null,
            'failure_reason' => $execution['failure_reason'] ?? null,
            'residual_unhedged_quantity' => $execution['residual_unhedged_quantity'] ?? null,
            'orders' => count($orders),
            'fills' => count($fills),
        ];
    }

    /** @param array<string,mixed> $execution */
    private function hedgeStateFromExecution(array $execution): string
    {
        $explicit = strtoupper((string)($execution['hedge_state'] ?? $execution['hedge_status'] ?? $execution['hedge'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return match (strtoupper((string)($execution['status'] ?? ''))) {
            'PARTIALLY_EXECUTED' => 'PARTIALLY_HEDGED',
            'COMPENSATING' => 'RECOVERY_REQUIRED',
            'COMPLETED' => 'HEDGED',
            'INVALIDATED', 'FAILED' => 'UNHEDGED',
            default => 'UNKNOWN',
        };
    }

    /** @param array<string,mixed> $opportunity @return array<string,mixed> */
    private function economics(array $opportunity): array
    {
        return [
            'gross_edge' => $opportunity['gross_edge'] ?? $opportunity['gross_pnl'] ?? $opportunity['gross_profit'] ?? null,
            'trading_fees' => $opportunity['trading_fees'] ?? $opportunity['fees'] ?? null,
            'slippage' => $opportunity['slippage'] ?? $opportunity['slippage_cost'] ?? null,
            'funding' => $opportunity['funding'] ?? $opportunity['funding_pnl'] ?? null,
            'borrow' => $opportunity['borrow'] ?? $opportunity['borrow_cost'] ?? null,
            'other' => $opportunity['other_costs'] ?? null,
            'expected_net' => $opportunity['expected_net_pnl'] ?? $opportunity['expected_net'] ?? $opportunity['net_pnl'] ?? null,
        ];
    }

    /** @param array<string,mixed> $opportunity @param array<string,mixed> $market @return array<string,mixed> */
    private function opportunityEvidence(array $opportunity, array $market): array
    {
        $instrumentIds = $this->strings($opportunity, ['instrument_id','instrument_ids','buy_instrument_id','sell_instrument_id','spot_instrument_id','perpetual_instrument_id']);
        $states = array_values(array_filter(
            $market['states'] ?? [],
            static fn(array $state): bool => in_array((string)($state['instrument_id'] ?? ''), $instrumentIds, true),
        ));
        $references = array_values(array_filter(
            $market['reference_states'] ?? [],
            static fn(array $state): bool => in_array((string)($state['instrument_id'] ?? ''), $instrumentIds, true),
        ));

        return [
            'market_states' => $states,
            'reference_states' => $references,
            'why' => $opportunity['reason'] ?? $opportunity['thesis'] ?? $opportunity['edge_reason'] ?? null,
            'liquidity' => $opportunity['liquidity'] ?? null,
            'execution_probability' => $opportunity['execution_probability'] ?? null,
        ];
    }

    /** @param array<string,mixed> $detail @return list<array<string,mixed>> */
    private function decisionTrace(array $detail): array
    {
        $opportunity = is_array($detail['opportunity'] ?? null) ? $detail['opportunity'] : [];
        $allocation = is_array($detail['allocation_item'] ?? null) ? $detail['allocation_item'] : [];
        $risk = is_array($detail['risk'] ?? null) ? $detail['risk'] : [];
        $execution = is_array($detail['execution'] ?? null) ? $detail['execution'] : [];

        $trace = [];
        if (($opportunity['hypothesis'] ?? null) !== null) {
            $trace[] = ['type'=>'Hypothesis','label'=>(string)$opportunity['hypothesis'],'href'=>'/capital-markets/research'];
        }
        $strategy = (string)($opportunity['strategy_version_id'] ?? $opportunity['strategy_version'] ?? $opportunity['strategy_id'] ?? '');
        if ($strategy !== '') {
            $trace[] = ['type'=>'Strategy','label'=>$strategy,'href'=>'/capital-markets/strategies/'.rawurlencode($strategy)];
        }
        $opportunityId = (string)($opportunity['opportunity_id'] ?? $opportunity['id'] ?? '');
        if ($opportunityId !== '') {
            $trace[] = ['type'=>'Opportunity','label'=>$opportunityId,'href'=>'/capital-markets/opportunities/'.rawurlencode($opportunityId)];
        }
        if ($allocation !== []) {
            $trace[] = ['type'=>'Allocation','label'=>(string)($allocation['decision'] ?? 'PROPOSED'),'value'=>$allocation['approved_capital'] ?? null,'href'=>'/capital-markets/allocation'];
        }
        if ($risk !== []) {
            $trace[] = ['type'=>'Risk','label'=>(string)($risk['status'] ?? 'ASSESSED'),'href'=>'/capital-markets/risk'];
        }
        if ($execution !== []) {
            $executionId = (string)($execution['execution_id'] ?? $execution['id'] ?? '');
            $trace[] = ['type'=>'Execution','label'=>$executionId === '' ? (string)($execution['status'] ?? 'EXECUTION') : $executionId,'href'=>$executionId === '' ? '/capital-markets/execution' : '/capital-markets/execution/'.rawurlencode($executionId)];

            $performance = is_array($execution['performance'] ?? null) ? $execution['performance'] : [];
            $netPnl = $performance['net_pnl'] ?? $execution['realized_pnl'] ?? $execution['pnl'] ?? null;
            if ($netPnl !== null && is_scalar($netPnl) && trim((string)$netPnl) !== '') {
                $trace[] = [
                    'type' => 'P&L',
                    'label' => 'Net P&L',
                    'value' => (string)$netPnl,
                    'href' => '/capital-markets/performance',
                ];
            }
        }
        return $trace;
    }

    /** @param array<string,mixed>|null $hypothesis */
    private function researchDecision(?array $hypothesis): string
    {
        if ($hypothesis === null) {
            return 'UNAVAILABLE';
        }
        return match (strtoupper((string)($hypothesis['status'] ?? ''))) {
            'VALIDATED', 'PROMOTED' => 'VALIDATED',
            'REJECTED', 'FAILED' => 'REJECTED',
            default => 'KEEP TESTING',
        };
    }

    /** @param array<string,mixed> $research @return array<string,list<array<string,mixed>>> */
    private function researchPipeline(array $research): array
    {
        $pipeline = [
            'IDEAS'=>[], 'RESEARCH'=>[], 'BACKTEST'=>[], 'OOS'=>[], 'PAPER'=>[], 'VALIDATED'=>[], 'REJECTED'=>[],
        ];
        foreach ($research['hypotheses'] ?? [] as $hypothesis) {
            if (!is_array($hypothesis)) {
                continue;
            }
            $status = strtoupper((string)($hypothesis['status'] ?? 'IDEAS'));
            $stage = match ($status) {
                'DRAFT','IDEA','IDEAS' => 'IDEAS',
                'READY_FOR_RESEARCH','RESEARCH','ACTIVE' => 'RESEARCH',
                'BACKTEST' => 'BACKTEST',
                'OOS' => 'OOS',
                'PAPER' => 'PAPER',
                'VALIDATED' => 'VALIDATED',
                'REJECTED' => 'REJECTED',
                default => 'RESEARCH',
            };
            $pipeline[$stage][] = $hypothesis;
        }
        foreach ($research['rejections'] ?? [] as $rejection) {
            if (is_array($rejection)) {
                $pipeline['REJECTED'][] = $rejection;
            }
        }
        return $pipeline;
    }

    /** @param array<string,mixed>|null $hypothesis @param array<string,mixed> $research @return list<array<string,mixed>> */
    private function hypothesisTrace(string $hypothesisId, array $research): array
    {
        $trace = [['type'=>'Hypothesis','label'=>$hypothesisId,'href'=>'/capital-markets/research/hypotheses/'.rawurlencode($hypothesisId)]];
        foreach ($research['experiments'] ?? [] as $experiment) {
            if (!is_array($experiment) || (string)($experiment['hypothesis_id'] ?? '') !== $hypothesisId) {
                continue;
            }
            $trace[] = ['type'=>'Experiment','label'=>(string)($experiment['experiment_id'] ?? $experiment['id'] ?? 'Experiment'),'href'=>'/capital-markets/research'];
            $strategy = (string)($experiment['strategy_version_id'] ?? '');
            if ($strategy !== '') {
                $trace[] = ['type'=>'Strategy','label'=>$strategy,'href'=>'/capital-markets/strategies/'.rawurlencode($strategy)];
            }
            break;
        }
        return $trace;
    }

    /** @param list<array<string,mixed>> $opportunities @param list<array<string,mixed>> $positions @return array<string,int> */
    private function edgeFunnel(array $opportunities, array $positions): array
    {
        $detected = count($opportunities);
        $validated = count(array_filter($opportunities, static fn(array $row): bool => in_array(strtoupper((string)($row['status'] ?? '')), ['VALIDATED','EXECUTABLE','APPROVED','OPEN','COMPLETED'], true)));
        $executable = count(array_filter($opportunities, static fn(array $row): bool => in_array(strtoupper((string)($row['status'] ?? '')), ['EXECUTABLE','APPROVED','OPEN','COMPLETED'], true)));
        $executed = count(array_filter($positions, static fn(array $row): bool => (string)($row['execution_id'] ?? '') !== ''));
        $profitable = count(array_filter($positions, static fn(array $row): bool => is_numeric($row['realized_pnl'] ?? null) && (string)$row['realized_pnl'] !== '0' && !str_starts_with((string)$row['realized_pnl'], '-')));
        return [
            'detected'=>$detected,
            'validated'=>$validated,
            'executable'=>$executable,
            'executed'=>$executed,
            'profitable'=>$profitable,
        ];
    }

    /** @param array<string,mixed> $performance @param list<array<string,mixed>> $opportunities @return array<string,mixed> */
    private function costBreakdown(array $performance, array $opportunities): array
    {
        $canonical = is_array($performance['costs_by_type'] ?? null) ? $performance['costs_by_type'] : [];
        $items = [];
        foreach ([
            'trading_fees' => 'Trading Fees',
            'borrow' => 'Borrow',
            'network' => 'Network',
            'slippage' => 'Slippage',
            'data_api' => 'Data / API',
            'ai' => 'AI',
        ] as $key => $label) {
            $value = $canonical[$key] ?? null;
            $items[$key] = [
                'label' => $label,
                'available' => $value !== null,
                'value' => $value,
            ];
        }

        if (($items['slippage']['available'] ?? false) === false) {
            foreach ($opportunities as $row) {
                if (is_array($row) && (array_key_exists('slippage', $row) || array_key_exists('slippage_cost', $row))) {
                    $items['slippage']['source_available'] = true;
                    break;
                }
            }
        }

        return $items;
    }

    /** @param array<string,mixed> $source @param list<string> $keys @return list<string> */
    private function strings(array $source, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $values[] = trim($item);
                    }
                }
            }
        }
        return array_values(array_unique($values));
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $keys @return array<string,mixed>|null */
    private function findById(array $rows, string $id, array $keys): ?array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($keys as $key) {
                if ((string)($row[$key] ?? '') === $id) {
                    return $row;
                }
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function forStrategy(array $rows, string $strategyId): array
    {
        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => (string)($row['strategy_version_id'] ?? '') === $strategyId,
        ));
    }

    /**
     * @template T of array
     * @param callable():T $reader
     * @param T $fallback
     * @param list<array{source:string,message:string}> $errors
     * @return T
     */
    private function safe(callable $reader, array $fallback, array &$errors, string $source): array
    {
        try {
            return $reader();
        } catch (Throwable $error) {
            $errors[] = ['source'=>$source, 'message'=>$error->getMessage()];
            return $fallback;
        }
    }
}
