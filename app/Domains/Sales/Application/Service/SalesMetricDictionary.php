<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\DTO\SalesMetricDefinition;

final class SalesMetricDictionary
{
    /** @return array<string,SalesMetricDefinition> */
    public function definitions(): array
    {
        $history = ['Historical metrics must exclude ESTIMATED timestamps from exact duration/conversion calculations.', 'PARTIAL history must remain visible in quality breakdowns.'];

        return [
            'pipeline_value' => new SalesMetricDefinition(
                'pipeline_value', 'Pipeline Value',
                'Nominal value of currently open Deals.',
                'SUM(deal_value) for open non-terminal Deals', 'n/a', 'current state',
                ['organization', 'pipeline'], 'Group and total strictly by ISO currency; never sum different currencies.',
                ['Deal must have a known currency and deal_value.'],
            ),
            'weighted_pipeline' => new SalesMetricDefinition(
                'weighted_pipeline', 'Weighted Pipeline',
                'Probability-weighted nominal value of currently open Deals.',
                'SUM(deal_value × probability / 100)', 'n/a', 'current state',
                ['organization', 'pipeline'], 'Group and total strictly by ISO currency; never sum different currencies.',
                ['Deal must have deal_value and currency; probability uses Deal value first, then current stage default.'],
            ),
            'win_rate_closed' => new SalesMetricDefinition(
                'win_rate_closed', 'Win Rate — Closed Deals',
                'Share of terminal Deal outcomes in the period that are WON.',
                'distinct Deals with sales.deal.won in period', 'distinct Deals with sales.deal.won or sales.deal.lost in period',
                'terminal outcome event occurred_at', ['organization', 'pipeline', 'period'], 'not monetary',
                ['Canonical terminal outcome events are required.'],
            ),
            'win_rate_created' => new SalesMetricDefinition(
                'win_rate_created', 'Win Rate — Created Cohort',
                'Share of Deals created in the cohort period that reached WON by the cohort observation end.',
                'created cohort Deals with sales.deal.won before period_end', 'distinct sales.deal.created Deals in cohort period',
                'DealCreated cohort, observed through period_end', ['organization', 'pipeline', 'period'], 'not monetary',
                ['Canonical DealCreated and DealWon events are required.'],
            ),
            'stage_conversion' => new SalesMetricDefinition(
                'stage_conversion', 'Stage Conversion',
                'Observed transition share from a source stage to a specific next stage.',
                'historical transitions from A to B', 'all historical exits from A in the period',
                'destination stage entered_at', ['organization', 'pipeline', 'period', 'from_stage', 'to_stage'], 'not monetary',
                $history,
            ),
            'stage_duration' => new SalesMetricDefinition(
                'stage_duration', 'Stage Duration',
                'Distribution of elapsed time spent in a stage before exit.',
                'duration_seconds samples', 'completed stage-history rows', 'stage left_at',
                ['organization', 'pipeline', 'period', 'stage'], 'not monetary',
                [...$history, 'duration_seconds and left_at must be known.'],
            ),
            'sales_cycle' => new SalesMetricDefinition(
                'sales_cycle', 'Sales Cycle',
                'Elapsed time from canonical DealCreated to canonical DealWon.',
                'won_at − created_at in seconds', 'Deals won in the period with a known canonical create event',
                'DealWon occurred_at; trend bucket is calendar month of WON', ['organization', 'pipeline', 'period'], 'not monetary',
                ['Canonical DealCreated and DealWon events are required; negative or ambiguous cycles are rejected.'],
            ),
            'stuck_deals' => new SalesMetricDefinition(
                'stuck_deals', 'Stuck Deals',
                'Open Deals whose exact current-stage age and inactivity both breach the configured stage threshold and that have no future next contact.',
                'count of qualifying open Deals', 'open Deals on stages with stuck_after_seconds configured', 'as-of snapshot',
                ['organization', 'pipeline', 'stage', 'as_of'], 'not monetary',
                ['Current stage entered_at must be exact enough to compare; stages without a configured threshold are not guessed.'],
            ),
            'at_risk_revenue' => new SalesMetricDefinition(
                'at_risk_revenue', 'At-risk Revenue',
                'Nominal value of open Deals classified at risk because they are stuck, overdue for next contact, or past expected close.',
                'SUM(deal_value) of at-risk open Deals', 'n/a', 'as-of snapshot',
                ['organization', 'pipeline', 'currency', 'as_of'], 'Group strictly by currency; never combine currencies.',
                ['Deal must have currency and deal_value to contribute revenue; risk reasons remain explicit.'],
            ),
            'cohort_funnel' => new SalesMetricDefinition(
                'cohort_funnel', 'Cohort Funnel',
                'For Deals created in a selected cohort, how many reached each stage by period_end.',
                'distinct cohort Deals that reached stage', 'distinct DealCreated events in cohort period',
                'DealCreated cohort, observed through period_end', ['organization', 'pipeline', 'period', 'stage'], 'not monetary',
                $history,
            ),
            'transition_flow' => new SalesMetricDefinition(
                'transition_flow', 'Transition Flow',
                'Actual stage-to-stage movements that occurred during the selected period.',
                'historical transition records', 'n/a', 'destination stage entered_at',
                ['organization', 'pipeline', 'period', 'from_stage', 'to_stage'], 'not monetary',
                $history,
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function asArray(): array
    {
        return array_values(array_map(
            static fn (SalesMetricDefinition $definition): array => $definition->toArray(),
            $this->definitions(),
        ));
    }
}
