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
            'response_time' => new SalesMetricDefinition(
                'response_time', 'Response Time',
                'Time from the first inbound message in an unanswered Deal/channel burst to the first outbound reply.',
                'first outbound occurred_at − first inbound occurred_at', 'response opportunities whose first inbound occurred in period',
                'first inbound occurred_at', ['organization', 'pipeline', 'period', 'channel', 'owner'], 'not monetary',
                ['Communication direction and occurred_at must be known.', 'Manager attribution requires a known-timestamp owner interval at first inbound; ESTIMATED owners are never used.'],
            ),
            'followup_compliance' => new SalesMetricDefinition(
                'followup_compliance', 'Follow-up Compliance',
                'Completion and on-time execution of follow-up activities whose due time falls in the observed period.',
                'completed or completed on-time follow-ups', 'follow-ups due by observation_end',
                'follow-up due_at', ['organization', 'pipeline', 'period', 'owner'], 'not monetary',
                ['due_at and completed_at are authoritative current facts.', 'Pre-V0.8.3 reschedule history is PARTIAL and is not reconstructed.'],
            ),
            'loss_analysis' => new SalesMetricDefinition(
                'loss_analysis', 'Loss Analysis',
                'Distribution of canonical LOST outcomes by reason, with immutable monetary snapshots where available.',
                'lost Deal outcomes grouped by reason', 'all sales.deal.lost outcomes in period',
                'DealLost occurred_at', ['organization', 'pipeline', 'period', 'reason', 'currency'],
                'Lost value is grouped by currency; different currencies are never combined.',
                ['Reason uses the canonical DealLost event.', 'Historical value/currency may be absent before V0.8.3 and must remain missing rather than copied from current Deal state.'],
            ),
            'manager_performance' => new SalesMetricDefinition(
                'manager_performance', 'Manager Performance',
                'Operational response, follow-up and terminal-outcome metrics attributed to the manager proven at each fact timestamp.',
                'per-manager attributed operational facts', 'only facts with a provable owner-at-time for manager-specific rates',
                'fact timestamp: first inbound, follow-up due_at, or terminal outcome occurred_at',
                ['organization', 'pipeline', 'period', 'owner'],
                'Lost value remains grouped by currency; no mixed-currency manager total exists.',
                ['Current assigned_user_id must never be applied retroactively.', 'COMPLETE/PARTIAL owner intervals require a known assigned_at; ESTIMATED current-state owners are excluded.', 'Unattributed facts remain visible through coverage.'],
            ),
            'forecast_value' => new SalesMetricDefinition(
                'forecast_value', 'Forecast Value',
                'Nominal value of currently open Deals whose current expected_close_at falls inside the forecast window.',
                'SUM(deal_value) for open Deals with expected_close_at in [from,to)', 'n/a',
                'current expected_close_at snapshot at as_of', ['organization', 'pipeline', 'forecast_window'],
                'Group strictly by currency; never combine currencies.',
                ['Deal must have expected_close_at, deal_value and currency to contribute.', 'This is a current snapshot, not reconstructed historical forecast.'],
            ),
            'weighted_forecast' => new SalesMetricDefinition(
                'weighted_forecast', 'Weighted Forecast',
                'Forecast Value weighted by the Deal probability, falling back only to the configured current-stage default.',
                'SUM(deal_value × valid probability / 100) for Deals in forecast window', 'n/a',
                'current expected_close_at and probability snapshot at as_of', ['organization', 'pipeline', 'forecast_window'],
                'Group strictly by currency; never combine currencies.',
                ['Probability source must be DEAL or STAGE_DEFAULT and within 0..100.', 'Missing or invalid probability is excluded rather than guessed.', 'Operational probability is not a calibrated statistical prediction.'],
            ),
            'forecast_coverage' => new SalesMetricDefinition(
                'forecast_coverage', 'Forecast Coverage',
                'Completeness of scheduling, money, probability and exact stage-age inputs used by forecast/risk.',
                'facts with the required input', 'eligible open or forecast-window Deals',
                'current snapshot at as_of', ['organization', 'pipeline', 'forecast_window'], 'not monetary',
                ['Coverage is reported explicitly so missing data cannot masquerade as certainty.'],
            ),
            'risk_explainability' => new SalesMetricDefinition(
                'risk_explainability', 'Risk Explainability',
                'Per-Deal deterministic risk reasons with observable evidence; no opaque composite score.',
                'explicit STUCK / NEXT_CONTACT_OVERDUE / EXPECTED_CLOSE_OVERDUE reasons', 'open non-terminal Deals',
                'current snapshot at as_of', ['organization', 'pipeline', 'as_of'],
                'At-risk values are grouped strictly by currency; never combine currencies.',
                ['STUCK requires configured threshold and known stage entered_at.', 'ESTIMATED stage age cannot trigger STUCK.', 'Every returned risk reason includes its factual evidence.'],
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
