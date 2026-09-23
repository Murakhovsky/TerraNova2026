<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;
use App\Web\Sales\ViewModel\SalesDirectorGridViewModel;
use App\Web\Sales\ViewModel\SalesDirectorViewModel;

final class SalesDirectorPresenter
{
    /** @param array<string,mixed> $data */
    public function present(
        array $data,
        int $historyDays,
        int $forecastDays,
        ?string $pipelineId,
        ?string $error = null,
    ): SalesDirectorViewModel {
        $headline = $this->array($data['headline'] ?? null);
        $historical = $this->array($data['historical'] ?? null);
        $operations = $this->array($data['operations'] ?? null);
        $forecast = $this->array($data['forecast'] ?? null);
        $quality = $this->array($data['quality'] ?? null);

        return new SalesDirectorViewModel(
            filters: [
                'history_days' => max(1, min(366, $historyDays)),
                'forecast_days' => max(1, min(366, $forecastDays)),
                'pipeline_id' => $pipelineId ?? '',
            ],
            kpis: [
                ['label' => 'Closed win rate', 'value' => $this->percent($this->array($headline['closed_win_rate'] ?? null)['rate'] ?? null)],
                ['label' => 'Created cohort win rate', 'value' => $this->percent($this->array($headline['created_cohort_win_rate'] ?? null)['rate'] ?? null)],
                ['label' => 'Stuck deals', 'value' => (string) (int) ($headline['stuck_deal_count'] ?? 0)],
                ['label' => 'Forecast coverage', 'value' => $this->percent($this->array($forecast['coverage'] ?? null)['expected_close_rate'] ?? null)],
            ],
            executiveGrid: $this->grid(
                $this->executiveRows($headline),
                [
                    new DataGridColumn('currency', 'Currency', mobilePriority: 10),
                    new DataGridColumn('pipeline', 'Pipeline', mobilePriority: 20, align: 'end'),
                    new DataGridColumn('weighted', 'Weighted pipeline', mobilePriority: 30, align: 'end'),
                    new DataGridColumn('forecast', 'Forecast', mobilePriority: 40, align: 'end'),
                    new DataGridColumn('risk', 'Current at risk', mobilePriority: 50, align: 'end'),
                ],
            ),
            transitionGrid: $this->grid(
                $this->transitionRows($historical),
                [
                    new DataGridColumn('pipeline', 'Pipeline', mobilePriority: 10),
                    new DataGridColumn('from', 'From', mobilePriority: 20),
                    new DataGridColumn('to', 'To', mobilePriority: 30),
                    new DataGridColumn('transitions', 'Transitions', mobilePriority: 40, align: 'end'),
                    new DataGridColumn('conversion', 'Conversion', mobilePriority: 50, align: 'end'),
                ],
            ),
            velocity: [
                ['label' => 'Sales cycle median', 'value' => $this->duration($this->array($historical['sales_cycle'] ?? null)['median_seconds'] ?? null)],
                ['label' => 'Sales cycle p75', 'value' => $this->duration($this->array($historical['sales_cycle'] ?? null)['p75_seconds'] ?? null)],
                ['label' => 'Cycle samples', 'value' => (string) (int) ($this->array($historical['sales_cycle'] ?? null)['sample_count'] ?? 0)],
            ],
            managerGrid: $this->grid(
                $this->managerRows($operations),
                [
                    new DataGridColumn('owner', 'Owner', mobilePriority: 10),
                    new DataGridColumn('response', 'Response', mobilePriority: 20, align: 'end'),
                    new DataGridColumn('median_response', 'Median response', mobilePriority: 30, align: 'end'),
                    new DataGridColumn('followup', 'Follow-up', mobilePriority: 40, align: 'end'),
                    new DataGridColumn('on_time', 'On time', mobilePriority: 50, align: 'end'),
                    new DataGridColumn('won_closed', 'Won / closed', mobilePriority: 60, align: 'end'),
                ],
            ),
            riskGrid: $this->grid(
                $this->riskRows($forecast),
                [
                    new DataGridColumn('deal', 'Deal', mobilePriority: 10),
                    new DataGridColumn('stage', 'Stage', mobilePriority: 20),
                    new DataGridColumn('expected_close', 'Expected close', mobilePriority: 30),
                    new DataGridColumn('reasons', 'Risk reasons', mobilePriority: 40),
                    new DataGridColumn('money', 'Money', mobilePriority: 50, align: 'end'),
                ],
            ),
            qualityNotes: [
                (string) ($quality['historical_policy'] ?? ''),
                $this->attributionNote($this->array($quality['performance_attribution'] ?? null)),
                trim((string) ($quality['currency_policy'] ?? '') . ' Currencies are never mixed.'),
            ],
            error: $error,
        );
    }

    /** @param array<string,mixed> $headline @return list<array<string,string>> */
    private function executiveRows(array $headline): array
    {
        $pipelineRows = $this->list($headline['pipeline_by_currency'] ?? null);
        $forecastRows = $this->list($headline['forecast_by_currency'] ?? null);
        $riskRows = $this->list($headline['at_risk_by_currency'] ?? null);

        $currencies = [];
        foreach ([$pipelineRows, $forecastRows, $riskRows] as $source) {
            foreach ($source as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if ($currency !== '') {
                    $currencies[$currency] = true;
                }
            }
        }
        ksort($currencies);

        $rows = [];
        foreach (array_keys($currencies) as $currency) {
            $pipeline = $this->currency($pipelineRows, $currency);
            $forecast = $this->currency($forecastRows, $currency);
            $risk = $this->currency($riskRows, $currency);

            $rows[] = [
                'currency' => $currency,
                'pipeline' => $this->money($pipeline['pipeline_value'] ?? 0, $currency),
                'weighted' => $this->money($pipeline['weighted_pipeline'] ?? 0, $currency),
                'forecast' => $this->money($forecast['value'] ?? 0, $currency),
                'risk' => $this->money($risk['value'] ?? 0, $currency),
            ];
        }

        return $rows;
    }

    /** @param array<string,mixed> $historical @return list<array<string,string>> */
    private function transitionRows(array $historical): array
    {
        $rows = [];
        foreach ($this->list($historical['stage_conversion'] ?? null) as $row) {
            $rows[] = [
                'pipeline' => (string) ($row['pipeline_id'] ?? ''),
                'from' => (string) ($row['from_stage_code'] ?? ''),
                'to' => (string) ($row['to_stage_code'] ?? ''),
                'transitions' => (string) (int) ($row['transition_count'] ?? 0),
                'conversion' => $this->percent($row['conversion_rate'] ?? null),
            ];
        }

        return $rows;
    }

    /** @param array<string,mixed> $operations @return list<array<string,string>> */
    private function managerRows(array $operations): array
    {
        $rows = [];
        $performance = $this->array($operations['manager_performance'] ?? null);

        foreach ($this->list($performance['managers'] ?? null) as $manager) {
            $response = $this->array($manager['response'] ?? null);
            $followup = $this->array($manager['followup'] ?? null);
            $outcomes = $this->array($manager['outcomes'] ?? null);

            $rows[] = [
                'owner' => '#' . (int) ($manager['owner_id'] ?? 0),
                'response' => $this->percent($response['response_rate'] ?? null),
                'median_response' => $this->duration($response['median_seconds'] ?? null),
                'followup' => $this->percent($followup['completion_rate'] ?? null),
                'on_time' => $this->percent($followup['on_time_rate'] ?? null),
                'won_closed' => $this->percent($outcomes['win_rate_closed'] ?? null),
            ];
        }

        return $rows;
    }

    /** @param array<string,mixed> $forecast @return list<array<string,string>> */
    private function riskRows(array $forecast): array
    {
        $rows = [];
        foreach ($this->list($forecast['deals'] ?? null) as $deal) {
            $risk = $this->array($deal['risk'] ?? null);
            if (!(bool) ($risk['at_risk'] ?? false)) {
                continue;
            }

            $codes = [];
            foreach ($this->list($risk['reasons'] ?? null) as $reason) {
                $code = trim((string) ($reason['code'] ?? ''));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }

            $money = $this->array($deal['money'] ?? null);
            $rows[] = [
                'deal' => (string) ($deal['title'] ?? $deal['deal_id'] ?? 'Deal'),
                'stage' => (string) ($deal['stage_code'] ?? '—'),
                'expected_close' => (string) ($deal['expected_close_at'] ?? '—'),
                'reasons' => $codes !== [] ? implode(', ', $codes) : '—',
                'money' => (bool) ($money['available'] ?? false)
                    ? $this->money($money['value'] ?? 0, (string) ($money['currency'] ?? ''))
                    : '—',
            ];
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @param list<DataGridColumn> $columns */
    private function grid(array $rows, array $columns): SalesDirectorGridViewModel
    {
        $count = count($rows);

        return new SalesDirectorGridViewModel(
            query: new DataGridQuery(perPage: max(10, $count)),
            page: new DataGridPage($rows, $count, 1, max(1, $count)),
            columns: $columns,
            state: $count === 0 ? DataGridState::Empty : DataGridState::Ready,
        );
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function currency(array $rows, string $currency): array
    {
        foreach ($rows as $row) {
            if (strtoupper((string) ($row['currency'] ?? '')) === $currency) {
                return $row;
            }
        }

        return [];
    }

    /** @param array<string,mixed> $coverage */
    private function attributionNote(array $coverage): string
    {
        $parts = [];
        foreach (['response', 'followup', 'outcome'] as $key) {
            $parts[] = $key . ' ' . $this->percent($this->array($coverage[$key] ?? null)['coverage'] ?? null);
        }

        return 'Attribution coverage: ' . implode(' · ', $parts) . '.';
    }

    private function percent(mixed $value): string
    {
        return $value === null || $value === ''
            ? '—'
            : number_format((float) $value * 100, 1, '.', ' ') . '%';
    }

    private function duration(mixed $seconds): string
    {
        return $seconds === null || $seconds === ''
            ? '—'
            : number_format((float) $seconds / 3600, 1, '.', ' ') . ' h';
    }

    private function money(mixed $value, string $currency): string
    {
        return number_format((float) $value, 0, '.', ' ') . ($currency !== '' ? ' ' . $currency : '');
    }

    /** @return array<string,mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
