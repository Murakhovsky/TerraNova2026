<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

/**
 * Computes actual business outcome against immutable criteria and evidence.
 * A completed job/workflow never implies a completed Goal.
 */
final readonly class GoalOutcomeEvaluator
{
    /**
     * @param array<string,array{value:mixed,evidence:list<string>}> $observations
     * @return array<string,mixed>
     */
    public function evaluate(GoalSpecification $specification, array $observations): array
    {
        $items = [];
        $satisfied = 0;
        $partial = 0;
        $unknown = 0;
        foreach ($specification->criteria as $criterion) {
            $id = $criterion['id'];
            $observed = $observations[$id]['value'] ?? null;
            $evidence = $observations[$id]['evidence'] ?? [];
            $state = 'unverifiable';
            if (is_array($evidence) && $evidence !== [] && $this->validEvidence($evidence) && is_scalar($observed)) {
                $expected = $criterion['expected'];
                if ($criterion['operator'] === 'equals') {
                    $state = $observed === $expected ? 'satisfied' : 'unsatisfied';
                } elseif (is_numeric($observed) && is_numeric($expected)) {
                    $actual = (float) $observed;
                    $target = (float) $expected;
                    $state = match ($criterion['operator']) {
                        'at_least' => $actual >= $target ? 'satisfied'
                            : ($actual > 0 && $target > 0 ? 'partial' : 'unsatisfied'),
                        'at_most' => $actual <= $target ? 'satisfied' : 'unsatisfied',
                        default => 'unverifiable',
                    };
                }
            }
            $satisfied += (int) ($state === 'satisfied');
            $partial += (int) ($state === 'partial');
            $unknown += (int) ($state === 'unverifiable');
            $items[] = [
                'criterion_id' => $id,
                'expected' => $criterion['expected'],
                'observed' => $observed,
                'evidence' => is_array($evidence) ? $evidence : [],
                'source' => $observations[$id]['source'] ?? null,
                'window_start' => $observations[$id]['window_start'] ?? null,
                'window_end' => $observations[$id]['window_end'] ?? null,
                'result' => $state,
            ];
        }
        $total = count($specification->criteria);
        $status = $satisfied === $total ? 'satisfied'
            : (($satisfied + $partial) > 0 ? 'partial' : ($unknown > 0 ? 'unverifiable' : 'unsatisfied'));
        return [
            'schema_version' => '1.0.0',
            'goal_id' => $specification->goalId,
            'organization_id' => $specification->organizationId,
            'specification_version' => $specification->version,
            'evaluator_type' => 'deterministic',
            'evaluated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'result' => $status,
            'criteria' => $items,
        ];
    }

    /** @param array<mixed> $evidence */
    private function validEvidence(array $evidence): bool
    {
        foreach ($evidence as $reference) {
            if (!is_string($reference) || $reference === '') {
                return false;
            }
        }
        return true;
    }
}
