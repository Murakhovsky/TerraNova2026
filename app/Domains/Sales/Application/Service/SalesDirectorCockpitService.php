<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SalesDirectorCockpitService
{
    public const CONTRACT_VERSION = 'sales.director.cockpit.v1';

    public function __construct(
        private SalesHistoricalMetricsService $historical,
        private SalesOperationalPerformanceService $operations,
        private SalesForecastRiskService $forecast,
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(
        string $organizationId,
        DateTimeImmutable $asOf,
        int $historyDays = 30,
        int $forecastDays = 30,
        ?string $pipelineId = null,
    ): array {
        if (trim($organizationId) === '') {
            throw new InvalidArgumentException('organizationId is required.');
        }
        if ($historyDays < 1 || $historyDays > 366) {
            throw new InvalidArgumentException('historyDays must be between 1 and 366.');
        }
        if ($forecastDays < 1 || $forecastDays > 366) {
            throw new InvalidArgumentException('forecastDays must be between 1 and 366.');
        }

        $historyFrom = $asOf->modify(sprintf('-%d days', $historyDays));
        $forecastTo = $asOf->modify(sprintf('+%d days', $forecastDays));
        $pipelineId = $pipelineId !== null && trim($pipelineId) !== '' ? trim($pipelineId) : null;

        $historical = $this->historical->metrics($organizationId, $historyFrom, $asOf, $pipelineId, $asOf);
        $operations = $this->operations->report($organizationId, $historyFrom, $asOf, $pipelineId);
        $forecast = $this->forecast->forecast($organizationId, $asOf, $forecastTo, $asOf, $pipelineId);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'as_of' => $asOf->format(DATE_ATOM),
            'filters' => [
                'history_days' => $historyDays,
                'forecast_days' => $forecastDays,
                'pipeline_id' => $pipelineId,
            ],
            'headline' => [
                'pipeline_by_currency' => $historical['pipeline_value'] ?? [],
                'forecast_by_currency' => $forecast['forecast_value_by_currency'] ?? [],
                'weighted_forecast_by_currency' => $forecast['weighted_forecast_by_currency'] ?? [],
                'at_risk_by_currency' => $forecast['current_at_risk_revenue_by_currency'] ?? [],
                'closed_win_rate' => $historical['win_rate_closed'] ?? null,
                'created_cohort_win_rate' => $historical['win_rate_created'] ?? null,
                'stuck_deal_count' => count((array) ($historical['stuck_deals'] ?? [])),
            ],
            'historical' => $historical,
            'operations' => $operations,
            'forecast' => $forecast,
            'quality' => [
                'forecast_coverage' => $forecast['coverage'] ?? [],
                'performance_attribution' => $operations['manager_performance']['attribution_coverage'] ?? [],
                'historical_policy' => 'Historical time metrics exclude ESTIMATED timestamps unless explicitly identified by the underlying metric.',
                'currency_policy' => 'Money is always returned by currency. The Director contract never creates a mixed-currency grand total.',
            ],
        ];
    }
}
