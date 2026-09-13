<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesForecastRiskReadModelInterface;
use InvalidArgumentException;

final readonly class SalesForecastRiskService
{
    public const FORMULA_VERSION = 'sales-forecast-risk-v0.8.4';

    public function __construct(private SalesForecastRiskReadModelInterface $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function forecast(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?DateTimeImmutable $asOf = null,
        ?string $pipelineId = null,
    ): array {
        if (trim($organizationId) === '') {
            throw new InvalidArgumentException('organizationId is required.');
        }
        if ($to <= $from) {
            throw new InvalidArgumentException('Forecast period end must be after start.');
        }
        $asOf ??= new DateTimeImmutable();

        $rows = $this->readModel->openDeals($organizationId, $asOf, $pipelineId);
        $deals = [];
        $nominal = [];
        $weighted = [];
        $atRisk = [];
        $forecastAtRisk = [];
        $forecastClear = [];
        $unscheduled = [];
        $coverage = [
            'open_deals' => count($rows),
            'with_expected_close' => 0,
            'in_forecast_window' => 0,
            'forecast_with_money' => 0,
            'forecast_with_valid_probability' => 0,
            'forecast_with_exact_stage_age' => 0,
        ];

        foreach ($rows as $row) {
            $explanation = $this->explain($row, $from, $to, $asOf);
            $deals[] = $explanation;

            if ($explanation['expected_close_at'] !== null) {
                $coverage['with_expected_close']++;
            } elseif ($explanation['money']['available']) {
                $this->addMoney($unscheduled, $explanation['money']['currency'], $explanation['money']['value']);
            }

            if ($explanation['forecast']['in_window']) {
                $coverage['in_forecast_window']++;
                if ($explanation['data_quality']['stage_age_available']) {
                    $coverage['forecast_with_exact_stage_age']++;
                }
                if ($explanation['money']['available']) {
                    $coverage['forecast_with_money']++;
                    $currency = $explanation['money']['currency'];
                    $value = $explanation['money']['value'];
                    $this->addMoney($nominal, $currency, $value);
                    if ($explanation['risk']['at_risk']) {
                        $this->addMoney($forecastAtRisk, $currency, $value);
                    } else {
                        $this->addMoney($forecastClear, $currency, $value);
                    }
                }
                if ($explanation['probability']['valid']) {
                    $coverage['forecast_with_valid_probability']++;
                    if ($explanation['money']['available']) {
                        $this->addMoney(
                            $weighted,
                            $explanation['money']['currency'],
                            $explanation['money']['value'] * $explanation['probability']['value'] / 100,
                        );
                    }
                }
            }

            if ($explanation['risk']['at_risk'] && $explanation['money']['available']) {
                $this->addMoney($atRisk, $explanation['money']['currency'], $explanation['money']['value']);
            }
        }

        $forecastDeals = $coverage['in_forecast_window'];
        $coverage['expected_close_rate'] = $this->ratio($coverage['with_expected_close'], $coverage['open_deals']);
        $coverage['forecast_money_rate'] = $this->ratio($coverage['forecast_with_money'], $forecastDeals);
        $coverage['forecast_probability_rate'] = $this->ratio($coverage['forecast_with_valid_probability'], $forecastDeals);
        $coverage['forecast_stage_age_rate'] = $this->ratio($coverage['forecast_with_exact_stage_age'], $forecastDeals);

        return [
            'formula_version' => self::FORMULA_VERSION,
            'period' => [
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
                'as_of' => $asOf->format(DATE_ATOM),
                'pipeline_id' => $pipelineId,
            ],
            'forecast_value_by_currency' => $this->moneyRows($nominal),
            'weighted_forecast_by_currency' => $this->moneyRows($weighted),
            'current_at_risk_revenue_by_currency' => $this->moneyRows($atRisk),
            'forecast_at_risk_revenue_by_currency' => $this->moneyRows($forecastAtRisk),
            'forecast_clear_revenue_by_currency' => $this->moneyRows($forecastClear),
            'unscheduled_open_revenue_by_currency' => $this->moneyRows($unscheduled),
            'coverage' => $coverage,
            'deals' => $deals,
            'explainability' => [
                'forecast_rule' => 'A Deal contributes nominal forecast only when current expected_close_at is inside [from,to) and value/currency are known.',
                'weighted_rule' => 'Weighted forecast uses the Deal probability when present, otherwise the configured current-stage default. Missing or out-of-range probability is excluded rather than guessed.',
                'risk_rule' => 'Risk has no opaque score. Reasons are STUCK, NEXT_CONTACT_OVERDUE and EXPECTED_CLOSE_OVERDUE, each with observable evidence.',
                'stuck_rule' => 'STUCK requires configured threshold, known stage-entered time, breached stage age, breached inactivity, and no future next contact.',
                'currency_rule' => 'Money is grouped by currency. Different currencies are never summed.',
            ],
            'data_quality' => [
                'forecast_is_snapshot' => 'Forecast uses current expected_close_at and current probability configuration at as_of; it is not a reconstructed historical forecast.',
                'probability_is_not_calibrated_prediction' => 'Probability is an operational CRM input/stage default, not a statistically calibrated probability of close.',
                'estimated_stage_history' => 'ESTIMATED stage history may identify the current stage but cannot prove its age and therefore cannot trigger STUCK.',
            ],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function explain(array $row, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $asOf): array
    {
        $asOfTs = $asOf->getTimestamp();
        $expected = $this->time($row['expected_close_at'] ?? null);
        $nextContact = $this->time($row['next_contact_at'] ?? null);
        $entered = $this->time($row['entered_at'] ?? null);
        $lastActivity = $this->time($row['last_activity_at'] ?? null);
        $threshold = isset($row['stuck_after_seconds']) ? (int) $row['stuck_after_seconds'] : 0;
        $historyQuality = strtoupper(trim((string) ($row['history_quality'] ?? '')));
        $stageAgeAvailable = $entered !== null && $historyQuality !== 'ESTIMATED';

        $stageAge = $stageAgeAvailable ? max(0, $asOfTs - $entered) : null;
        $inactivity = $lastActivity !== null ? max(0, $asOfTs - $lastActivity) : null;
        $ageBreached = $threshold > 0 && $stageAge !== null && $stageAge >= $threshold;
        $activityBreached = $threshold > 0 && ($lastActivity === null || ($asOfTs - $lastActivity) >= $threshold);
        $hasFutureContact = $nextContact !== null && $nextContact > $asOfTs;
        $isStuck = $ageBreached && $activityBreached && !$hasFutureContact;
        $overdueContact = $nextContact !== null && $nextContact <= $asOfTs;
        $overdueClose = $expected !== null && $expected <= $asOfTs;

        $reasons = [];
        if ($isStuck) {
            $reasons[] = [
                'code' => 'STUCK',
                'stage_age_seconds' => $stageAge,
                'inactivity_seconds' => $inactivity,
                'last_activity_missing' => $lastActivity === null,
                'threshold_seconds' => $threshold,
                'future_next_contact' => $hasFutureContact,
                'evidence_quality' => $historyQuality !== '' ? $historyQuality : 'PARTIAL',
            ];
        }
        if ($overdueContact) {
            $reasons[] = [
                'code' => 'NEXT_CONTACT_OVERDUE',
                'overdue_seconds' => max(0, $asOfTs - $nextContact),
                'next_contact_at' => $this->iso($row['next_contact_at'] ?? null),
                'evidence_quality' => 'CURRENT_STATE',
            ];
        }
        if ($overdueClose) {
            $reasons[] = [
                'code' => 'EXPECTED_CLOSE_OVERDUE',
                'overdue_seconds' => max(0, $asOfTs - $expected),
                'expected_close_at' => $this->iso($row['expected_close_at'] ?? null),
                'evidence_quality' => 'CURRENT_STATE',
            ];
        }

        $probability = $this->probability($row);
        $value = isset($row['deal_value']) && is_numeric($row['deal_value']) ? (float) $row['deal_value'] : null;
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        $moneyAvailable = $value !== null && $currency !== '';
        $inWindow = $expected !== null && $expected >= $from->getTimestamp() && $expected < $to->getTimestamp();

        return [
            'deal_id' => (string) ($row['deal_id'] ?? ''),
            'public_id' => $row['public_id'] ?? null,
            'title' => $row['title'] ?? null,
            'pipeline_id' => $row['pipeline_id'] ?? null,
            'stage_id' => $row['stage_id'] ?? null,
            'stage_code' => $row['stage_code'] ?? null,
            'current_owner_id' => isset($row['current_owner_id']) ? (int) $row['current_owner_id'] : null,
            'expected_close_at' => $this->iso($row['expected_close_at'] ?? null),
            'money' => [
                'available' => $moneyAvailable,
                'value' => $value,
                'currency' => $currency !== '' ? $currency : null,
            ],
            'probability' => $probability,
            'forecast' => [
                'in_window' => $inWindow,
                'nominal_contribution' => $inWindow && $moneyAvailable ? $value : null,
                'weighted_contribution' => $inWindow && $moneyAvailable && $probability['valid']
                    ? $value * $probability['value'] / 100
                    : null,
            ],
            'risk' => [
                'at_risk' => $reasons !== [],
                'factor_count' => count($reasons),
                'reasons' => $reasons,
            ],
            'data_quality' => [
                'stage_history_quality' => $historyQuality !== '' ? $historyQuality : null,
                'stage_age_available' => $stageAgeAvailable,
                'stuck_threshold_configured' => $threshold > 0,
                'probability_available' => $probability['valid'],
                'money_available' => $moneyAvailable,
            ],
        ];
    }

    /** @param array<string,mixed> $row @return array{source:string,value:?float,valid:bool,raw:?float} */
    private function probability(array $row): array
    {
        $source = 'MISSING';
        $raw = null;
        if (isset($row['deal_probability']) && is_numeric($row['deal_probability'])) {
            $source = 'DEAL';
            $raw = (float) $row['deal_probability'];
        } elseif (isset($row['stage_probability']) && is_numeric($row['stage_probability'])) {
            $source = 'STAGE_DEFAULT';
            $raw = (float) $row['stage_probability'];
        }
        $valid = $raw !== null && $raw >= 0.0 && $raw <= 100.0;
        return ['source' => $source, 'value' => $valid ? $raw : null, 'valid' => $valid, 'raw' => $raw];
    }

    /** @param array<string,float> $totals */
    private function addMoney(array &$totals, ?string $currency, ?float $value): void
    {
        if ($currency === null || $currency === '' || $value === null) return;
        $totals[$currency] = ($totals[$currency] ?? 0.0) + $value;
    }

    /** @param array<string,float> $totals @return list<array{currency:string,value:float}> */
    private function moneyRows(array $totals): array
    {
        ksort($totals);
        $rows = [];
        foreach ($totals as $currency => $value) {
            $rows[] = ['currency' => $currency, 'value' => $value];
        }
        return $rows;
    }

    private function ratio(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    private function time(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') return null;
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private function iso(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
