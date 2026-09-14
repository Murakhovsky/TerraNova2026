<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesHistoricalMetricsReadModelInterface;
use InvalidArgumentException;

final readonly class SalesHistoricalMetricsService
{
    public function __construct(private SalesHistoricalMetricsReadModelInterface $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function metrics(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $pipelineId = null,
        ?DateTimeImmutable $asOf = null,
    ): array {
        if ($organizationId === '') throw new InvalidArgumentException('organizationId is required.');
        if ($to <= $from) throw new InvalidArgumentException('Metric period end must be after start.');
        $asOf ??= $to;

        $closed = $this->readModel->closedOutcomes($organizationId, $from, $to, $pipelineId);
        $created = $this->readModel->createdCohortOutcomes($organizationId, $from, $to, $pipelineId);
        $flow = $this->readModel->transitionFlow($organizationId, $from, $to, $pipelineId);
        $cohort = $this->readModel->cohortFunnel($organizationId, $from, $to, $pipelineId);
        $stageDurations = $this->stageDurationMetrics(
            $this->readModel->stageDurationSamples($organizationId, $from, $to, $pipelineId),
        );
        $cycles = $this->salesCycleMetrics(
            $this->readModel->salesCycleSamples($organizationId, $from, $to, $pipelineId),
        );
        $risk = $this->riskMetrics(
            $this->readModel->openDealRiskFacts($organizationId, $asOf, $pipelineId),
            $asOf,
        );

        return [
            'period' => ['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM), 'as_of' => $asOf->format(DATE_ATOM)],
            'pipeline_value' => $this->readModel->pipelineMoney($organizationId, $pipelineId),
            'win_rate_closed' => [
                'won' => $closed['won'], 'closed' => $closed['closed'],
                'rate' => $this->ratio($closed['won'], $closed['closed']),
            ],
            'win_rate_created' => [
                'won' => $created['won'], 'created' => $created['created'],
                'rate' => $this->ratio($created['won'], $created['created']),
            ],
            'transition_flow' => $flow,
            'stage_conversion' => $this->stageConversion($flow),
            'cohort_funnel' => $this->cohortRates($cohort),
            'stage_duration' => $stageDurations,
            'sales_cycle' => $cycles,
            'stuck_deals' => $risk['stuck_deals'],
            'at_risk_revenue' => $risk['at_risk_revenue'],
        ];
    }

    /** @param list<array<string,mixed>> $flow @return list<array<string,mixed>> */
    private function stageConversion(array $flow): array
    {
        $denominators = [];
        foreach ($flow as $row) {
            $key = (string) ($row['pipeline_id'] ?? '') . '|' . (string) $row['from_stage_code'];
            $denominators[$key] = ($denominators[$key] ?? 0) + (int) $row['transition_count'];
        }
        $result = [];
        foreach ($flow as $row) {
            $key = (string) ($row['pipeline_id'] ?? '') . '|' . (string) $row['from_stage_code'];
            $denominator = (int) ($denominators[$key] ?? 0);
            $result[] = [
                ...$row,
                'from_stage_exits' => $denominator,
                'conversion_rate' => $this->ratio((int) $row['transition_count'], $denominator),
            ];
        }
        return $result;
    }

    /** @param array{created:int,stages:list<array<string,mixed>>} $cohort @return array<string,mixed> */
    private function cohortRates(array $cohort): array
    {
        $created = (int) $cohort['created'];
        $stages = [];
        foreach ($cohort['stages'] as $row) {
            $stages[] = [...$row, 'reach_rate' => $this->ratio((int) $row['reached_count'], $created)];
        }
        return ['created' => $created, 'stages' => $stages];
    }

    /** @param list<array<string,mixed>> $samples @return list<array<string,mixed>> */
    private function stageDurationMetrics(array $samples): array
    {
        $groups = [];
        foreach ($samples as $row) {
            $key = (string) ($row['pipeline_id'] ?? '') . '|' . (string) $row['stage_code'];
            $groups[$key]['pipeline_id'] = $row['pipeline_id'] ?? null;
            $groups[$key]['stage_code'] = (string) $row['stage_code'];
            $groups[$key]['samples'][] = (int) $row['duration_seconds'];
            $quality = (string) ($row['history_quality'] ?? 'PARTIAL');
            $groups[$key]['quality'][$quality] = ($groups[$key]['quality'][$quality] ?? 0) + 1;
        }
        $result = [];
        foreach ($groups as $group) {
            $values = $group['samples']; sort($values, SORT_NUMERIC);
            $result[] = [
                'pipeline_id' => $group['pipeline_id'], 'stage_code' => $group['stage_code'],
                'sample_count' => count($values), 'median_seconds' => $this->percentile($values, 0.50),
                'p75_seconds' => $this->percentile($values, 0.75), 'quality' => $group['quality'] ?? [],
            ];
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $samples @return array<string,mixed> */
    private function salesCycleMetrics(array $samples): array
    {
        $all = [];
        $trend = [];
        foreach ($samples as $row) {
            $seconds = (int) $row['cycle_seconds'];
            if ($seconds < 0) continue;
            $all[] = $seconds;
            $bucket = substr((string) $row['won_at'], 0, 7);
            $trend[$bucket][] = $seconds;
        }
        sort($all, SORT_NUMERIC);
        ksort($trend);
        $trendRows = [];
        foreach ($trend as $bucket => $values) {
            sort($values, SORT_NUMERIC);
            $trendRows[] = [
                'month' => $bucket, 'sample_count' => count($values),
                'median_seconds' => $this->percentile($values, 0.50),
                'p75_seconds' => $this->percentile($values, 0.75),
            ];
        }
        return [
            'sample_count' => count($all), 'median_seconds' => $this->percentile($all, 0.50),
            'p75_seconds' => $this->percentile($all, 0.75), 'trend' => $trendRows,
        ];
    }

    /** @param list<array<string,mixed>> $facts @return array<string,mixed> */
    private function riskMetrics(array $facts, DateTimeImmutable $asOf): array
    {
        $stuck = [];
        $revenue = [];
        foreach ($facts as $fact) {
            $threshold = isset($fact['stuck_after_seconds']) ? (int) $fact['stuck_after_seconds'] : 0;
            $enteredAt = $this->time($fact['entered_at'] ?? null);
            $lastActivityAt = $this->time($fact['last_activity_at'] ?? null);
            $nextContactAt = $this->time($fact['next_contact_at'] ?? null);
            $expectedCloseAt = $this->time($fact['expected_close_at'] ?? null);
            $now = $asOf->getTimestamp();

            $ageBreached = $threshold > 0 && $enteredAt !== null && ($now - $enteredAt) >= $threshold;
            $activityBreached = $threshold > 0 && ($lastActivityAt === null || ($now - $lastActivityAt) >= $threshold);
            $hasFutureContact = $nextContactAt !== null && $nextContactAt > $now;
            $isStuck = $ageBreached && $activityBreached && !$hasFutureContact;
            $overdueContact = $nextContactAt !== null && $nextContactAt <= $now;
            $pastExpectedClose = $expectedCloseAt !== null && $expectedCloseAt <= $now;
            $reasons = array_keys(array_filter([
                'STUCK' => $isStuck,
                'NEXT_CONTACT_OVERDUE' => $overdueContact,
                'EXPECTED_CLOSE_OVERDUE' => $pastExpectedClose,
            ]));

            if ($isStuck) $stuck[] = [...$fact, 'risk_reasons' => $reasons];
            if ($reasons !== [] && isset($fact['deal_value']) && is_numeric($fact['deal_value'])) {
                $currency = strtoupper(trim((string) ($fact['currency'] ?? '')));
                if ($currency !== '') {
                    $revenue[$currency] = ($revenue[$currency] ?? 0.0) + (float) $fact['deal_value'];
                }
            }
        }
        ksort($revenue);
        return [
            'stuck_deals' => $stuck,
            'at_risk_revenue' => array_map(
                static fn (string $currency, float $value): array => ['currency' => $currency, 'value' => $value],
                array_keys($revenue), array_values($revenue),
            ),
        ];
    }

    private function ratio(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, float $percentile): ?float
    {
        $count = count($sorted);
        if ($count === 0) return null;
        if ($count === 1) return (float) $sorted[0];
        $position = ($count - 1) * $percentile;
        $lower = (int) floor($position); $upper = (int) ceil($position);
        if ($lower === $upper) return (float) $sorted[$lower];
        $weight = $position - $lower;
        return $sorted[$lower] + (($sorted[$upper] - $sorted[$lower]) * $weight);
    }

    private function time(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') return null;
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }
}
