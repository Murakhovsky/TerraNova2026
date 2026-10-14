<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

/**
 * Attribute timeline entries only by exact persisted run or unique correlation.
 * Avoid pretending shared legacy traces carry unambiguous per-agent LLM spend.
 */
final readonly class EngineeringTimelineGrouper
{
    /**
     * @param list<array<string,mixed>> $timeline
     * @param list<array<string,mixed>> $runs
     * @return list<array<string,mixed>>
     */
    public function group(array $timeline, array $runs): array
    {
        $byRunId = [];
        $byTrace = [];
        foreach ($runs as $run) {
            if (!is_array($run)) continue;
            $id = trim((string) ($run['id'] ?? ''));
            if ($id === '') continue;
            $byRunId[$id] = $run;
            $trace = trim((string) ($run['trace_id'] ?? ''));
            if ($trace !== '') $byTrace[$trace][] = $id;
        }

        foreach ($timeline as &$event) {
            if (!is_array($event)) continue;
            $kind = strtoupper((string) ($event['type'] ?? ''));
            $ref = trim((string) ($event['reference_id'] ?? ''));
            $actor = trim((string) ($event['actor'] ?? ''));
            $trace = trim((string) ($event['correlation_id'] ?? ''));
            $runId = null;
            $attribution = 'UNATTRIBUTED';

            if ($kind === 'AGENT' && isset($byRunId[$ref])) {
                $runId = $ref;
                $attribution = 'EXACT_RUN_ID';
            } elseif (isset($byRunId[$actor])) {
                $runId = $actor;
                $attribution = 'EXACT_RUN_ID';
            } elseif ($trace !== '' && count($byTrace[$trace] ?? []) === 1) {
                $runId = $byTrace[$trace][0];
                $attribution = 'UNIQUE_TRACE';
            }

            if ($runId !== null) {
                $event['group_key'] = 'run:'.$runId;
                $event['group_label'] = (string) ($byRunId[$runId]['role'] ?? 'AGENT').' · запуск '.substr($runId, 0, 8);
            } else {
                $event['group_key'] = $kind === 'LLM' ? 'unattributed-llm' : 'system';
                $event['group_label'] = $kind === 'LLM'
                    ? 'LLM · без однозначної прив’язки'
                    : 'Системні та неатрибутовані події';
            }
            $event['attribution'] = $attribution;
            $round = $event['details']['round'] ?? null;
            $event['evidence_round'] = is_int($round) && $round >= 0 ? $round : null;
        }
        unset($event);

        return $timeline;
    }
}
