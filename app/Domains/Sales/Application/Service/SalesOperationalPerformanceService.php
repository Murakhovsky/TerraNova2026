<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesOperationalPerformanceReadModelInterface;
use InvalidArgumentException;

final readonly class SalesOperationalPerformanceService
{
    public function __construct(private SalesOperationalPerformanceReadModelInterface $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function report(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $pipelineId = null,
    ): array {
        if ($to <= $from) {
            throw new InvalidArgumentException('Sales performance period end must be after start.');
        }

        $communications = $this->readModel->communicationTimeline($organizationId, $from, $to, $pipelineId);
        $now = new DateTimeImmutable();
        $followupObservationEnd = $to <= $now ? $to : $now;
        $followups = $followupObservationEnd > $from
            ? $this->readModel->followupFacts($organizationId, $from, $followupObservationEnd, $pipelineId)
            : [];
        $outcomes = $this->readModel->terminalOutcomeFacts($organizationId, $from, $to, $pipelineId);

        $responseFacts = $this->responseFacts($communications, $from);
        $response = $this->responseSummary($responseFacts);
        $followup = $this->followupSummary($followups);
        $loss = $this->lossSummary($outcomes);
        $managers = $this->managerSummary($responseFacts, $followups, $outcomes);

        return [
            'period' => [
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
                'pipeline_id' => $pipelineId,
                'followup_observation_end' => $followupObservationEnd->format(DATE_ATOM),
            ],
            'response' => $response,
            'followup' => $followup,
            'loss' => $loss,
            'manager_performance' => $managers,
            'data_quality' => [
                'owner_attribution' => 'Only owner intervals with a known assignment timestamp (COMPLETE/PARTIAL) or immutable terminal-event owner snapshots are attributed. ESTIMATED current-state owners are never applied retroactively.',
                'followup_schedule_history' => 'PARTIAL: current due_at/completed_at facts are exact, but pre-V0.8.3 reschedule history was not immutable and cannot be reconstructed safely.',
                'loss_money' => 'Historical loss counts/reasons may predate immutable value/currency snapshots; missing money is reported as coverage loss, never filled from current Deal state.',
            ],
        ];
    }

    /** @param list<array<string,mixed>> $timeline @return list<array<string,mixed>> */
    private function responseFacts(array $timeline, DateTimeImmutable $from): array
    {
        $states = [];
        $facts = [];
        foreach ($timeline as $row) {
            $key = (string) $row['deal_id'] . '|' . strtoupper((string) $row['channel']);
            $direction = strtoupper((string) $row['direction']);
            $occurredAt = new DateTimeImmutable((string) $row['occurred_at']);
            $prePeriod = (bool) ($row['pre_period'] ?? false);

            if ($prePeriod) {
                $states[$key] = $direction === 'INBOUND' ? ['in_period' => false] : null;
                continue;
            }

            $open = $states[$key] ?? null;
            if ($direction === 'INBOUND') {
                if ($open === null) {
                    $states[$key] = [
                        'in_period' => $occurredAt >= $from,
                        'deal_id' => (string) $row['deal_id'],
                        'channel' => strtoupper((string) $row['channel']),
                        'started_at' => $occurredAt,
                        'owner_id' => $this->ownerId($row['owner_id'] ?? null),
                    ];
                }
                continue;
            }

            if ($direction === 'OUTBOUND' && is_array($open)) {
                if (($open['in_period'] ?? false) === true) {
                    $seconds = max(0, $occurredAt->getTimestamp() - $open['started_at']->getTimestamp());
                    $facts[] = $open + ['responded' => true, 'response_seconds' => $seconds, 'responded_at' => $occurredAt];
                }
                $states[$key] = null;
            }
        }

        foreach ($states as $open) {
            if (is_array($open) && ($open['in_period'] ?? false) === true) {
                $facts[] = $open + ['responded' => false, 'response_seconds' => null, 'responded_at' => null];
            }
        }
        return $facts;
    }

    /** @param list<array<string,mixed>> $facts @return array<string,mixed> */
    private function responseSummary(array $facts): array
    {
        $responded = array_values(array_filter($facts, static fn (array $fact): bool => $fact['responded'] === true));
        $samples = array_map(static fn (array $fact): int => (int) $fact['response_seconds'], $responded);
        $attributed = count(array_filter($facts, static fn (array $fact): bool => $fact['owner_id'] !== null));
        $total = count($facts);
        return [
            'opportunities' => $total,
            'responded' => count($responded),
            'unanswered' => $total - count($responded),
            'response_rate' => $this->ratio(count($responded), $total),
            'median_seconds' => $this->percentile($samples, 0.50),
            'p75_seconds' => $this->percentile($samples, 0.75),
            'attribution' => $this->coverage($attributed, $total),
            'definition' => 'First inbound message opens one Deal/channel response opportunity; additional inbound messages before the first outbound reply remain part of the same burst.',
        ];
    }

    /** @param list<array<string,mixed>> $followups @return array<string,mixed> */
    private function followupSummary(array $followups): array
    {
        $completed = $onTime = $late = $overdue = $attributed = 0;
        foreach ($followups as $row) {
            $due = new DateTimeImmutable((string) $row['due_at']);
            $done = isset($row['completed_at']) && $row['completed_at'] !== null && $row['completed_at'] !== ''
                ? new DateTimeImmutable((string) $row['completed_at']) : null;
            if ($done !== null) {
                $completed++;
                $done <= $due ? $onTime++ : $late++;
            } else {
                $overdue++;
            }
            if ($this->ownerId($row['owner_id'] ?? null) !== null) $attributed++;
        }
        $total = count($followups);
        return [
            'due' => $total,
            'completed' => $completed,
            'on_time' => $onTime,
            'late' => $late,
            'overdue' => $overdue,
            'completion_rate' => $this->ratio($completed, $total),
            'on_time_rate' => $this->ratio($onTime, $total),
            'attribution' => $this->coverage($attributed, $total),
            'schedule_history_quality' => 'PARTIAL',
        ];
    }

    /** @param list<array<string,mixed>> $outcomes @return array<string,mixed> */
    private function lossSummary(array $outcomes): array
    {
        $losses = array_values(array_filter($outcomes, static fn (array $row): bool => (string) $row['type'] === 'sales.deal.lost'));
        $reasons = [];
        $money = [];
        $moneySnapshots = 0;
        foreach ($losses as $row) {
            $reason = trim((string) ($row['lost_reason_id'] ?? '')) ?: 'UNSPECIFIED';
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            if ($row['deal_value'] !== null && $currency !== '') {
                $moneySnapshots++;
                $money[$reason][$currency] = ($money[$reason][$currency] ?? 0.0) + (float) $row['deal_value'];
            }
        }
        ksort($reasons);
        ksort($money);
        return [
            'lost' => count($losses),
            'by_reason' => $reasons,
            'lost_value_by_reason_currency' => $money,
            'monetary_snapshot_coverage' => $this->coverage($moneySnapshots, count($losses)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $responseFacts
     * @param list<array<string,mixed>> $followups
     * @param list<array<string,mixed>> $outcomes
     * @return array<string,mixed>
     */
    private function managerSummary(array $responseFacts, array $followups, array $outcomes): array
    {
        $managers = [];
        $coverage = [
            'response' => ['attributed' => 0, 'total' => count($responseFacts)],
            'followup' => ['attributed' => 0, 'total' => count($followups)],
            'outcome' => ['attributed' => 0, 'total' => count($outcomes)],
        ];

        foreach ($responseFacts as $fact) {
            $owner = $this->ownerId($fact['owner_id'] ?? null);
            if ($owner === null) continue;
            $coverage['response']['attributed']++;
            $manager =& $this->manager($managers, $owner);
            $manager['response']['opportunities']++;
            if ($fact['responded'] === true) {
                $manager['response']['responded']++;
                $manager['response']['samples'][] = (int) $fact['response_seconds'];
            }
            unset($manager);
        }

        foreach ($followups as $row) {
            $owner = $this->ownerId($row['owner_id'] ?? null);
            if ($owner === null) continue;
            $coverage['followup']['attributed']++;
            $manager =& $this->manager($managers, $owner);
            $manager['followup']['due']++;
            $done = isset($row['completed_at']) && $row['completed_at'] !== null && $row['completed_at'] !== ''
                ? new DateTimeImmutable((string) $row['completed_at']) : null;
            $due = new DateTimeImmutable((string) $row['due_at']);
            if ($done !== null) {
                $manager['followup']['completed']++;
                if ($done <= $due) $manager['followup']['on_time']++;
            }
            unset($manager);
        }

        foreach ($outcomes as $row) {
            $owner = $this->ownerId($row['owner_id'] ?? null);
            if ($owner === null) continue;
            $coverage['outcome']['attributed']++;
            $manager =& $this->manager($managers, $owner);
            $manager['outcomes']['closed']++;
            if ((string) $row['type'] === 'sales.deal.won') {
                $manager['outcomes']['won']++;
            } else {
                $manager['outcomes']['lost']++;
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if ($row['deal_value'] !== null && $currency !== '') {
                    $manager['outcomes']['lost_value_by_currency'][$currency]
                        = ($manager['outcomes']['lost_value_by_currency'][$currency] ?? 0.0) + (float) $row['deal_value'];
                }
            }
            unset($manager);
        }

        foreach ($managers as &$manager) {
            $responseTotal = $manager['response']['opportunities'];
            $followupTotal = $manager['followup']['due'];
            $closed = $manager['outcomes']['closed'];
            $manager['response']['response_rate'] = $this->ratio($manager['response']['responded'], $responseTotal);
            $manager['response']['median_seconds'] = $this->percentile($manager['response']['samples'], 0.50);
            $manager['response']['p75_seconds'] = $this->percentile($manager['response']['samples'], 0.75);
            unset($manager['response']['samples']);
            $manager['followup']['completion_rate'] = $this->ratio($manager['followup']['completed'], $followupTotal);
            $manager['followup']['on_time_rate'] = $this->ratio($manager['followup']['on_time'], $followupTotal);
            $manager['outcomes']['win_rate_closed'] = $this->ratio($manager['outcomes']['won'], $closed);
            ksort($manager['outcomes']['lost_value_by_currency']);
        }
        unset($manager);
        ksort($managers, SORT_NUMERIC);

        return [
            'managers' => array_values($managers),
            'attribution_coverage' => [
                'response' => $this->coverage($coverage['response']['attributed'], $coverage['response']['total']),
                'followup' => $this->coverage($coverage['followup']['attributed'], $coverage['followup']['total']),
                'outcome' => $this->coverage($coverage['outcome']['attributed'], $coverage['outcome']['total']),
            ],
            'definition' => 'Performance is attributed to the owner proven at the fact timestamp. Unattributed facts stay visible in coverage and are not assigned to the current Deal owner.',
        ];
    }

    /** @param array<int,array<string,mixed>> $managers @return array<string,mixed> */
    private function &manager(array &$managers, int $ownerId): array
    {
        if (!isset($managers[$ownerId])) {
            $managers[$ownerId] = [
                'owner_id' => $ownerId,
                'response' => ['opportunities' => 0, 'responded' => 0, 'samples' => []],
                'followup' => ['due' => 0, 'completed' => 0, 'on_time' => 0],
                'outcomes' => ['closed' => 0, 'won' => 0, 'lost' => 0, 'lost_value_by_currency' => []],
            ];
        }
        return $managers[$ownerId];
    }

    /** @param list<int> $samples */
    private function percentile(array $samples, float $percentile): ?float
    {
        if ($samples === []) return null;
        sort($samples, SORT_NUMERIC);
        $count = count($samples);
        if ($count === 1) return (float) $samples[0];
        $position = ($count - 1) * $percentile;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) return (float) $samples[$lower];
        $weight = $position - $lower;
        return $samples[$lower] + ($samples[$upper] - $samples[$lower]) * $weight;
    }

    private function ratio(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    /** @return array{attributed:int,unattributed:int,total:int,coverage:?float} */
    private function coverage(int $attributed, int $total): array
    {
        return [
            'attributed' => $attributed,
            'unattributed' => max(0, $total - $attributed),
            'total' => $total,
            'coverage' => $this->ratio($attributed, $total),
        ];
    }

    private function ownerId(mixed $value): ?int
    {
        $owner = (int) $value;
        return $owner > 0 ? $owner : null;
    }
}
