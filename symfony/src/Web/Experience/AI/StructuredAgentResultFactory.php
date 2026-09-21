<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;
use Kernel\Agent\AgentRunProjection;

final readonly class StructuredAgentResultFactory
{
    public function fromRun(AgentRunProjection $run): StructuredAgentResult
    {
        $output = $run->output;

        $summary = is_array($output['summary'] ?? null)
            ? $output['summary']
            : array_filter([
                'decision' => $this->string($output['decision'] ?? null),
                'reason' => $this->string($output['reason'] ?? null),
                'confidence' => $run->confidence,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $recommendations = [];
        $rawActions = $output['proposed_actions'] ?? $output['actions'] ?? [];
        if (is_array($rawActions)) {
            foreach ($rawActions as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $type = $this->string($action['type'] ?? null);
                if ($type === null) {
                    continue;
                }
                $recommendations[] = new AgentRecommendation(
                    type: $type,
                    targetType: $this->string($action['target_type'] ?? null),
                    targetId: $this->string($action['target_id'] ?? null),
                    parameters: is_array($action['parameters'] ?? null) ? $action['parameters'] : [],
                );
            }
        }

        $warnings = [];
        foreach ($this->list($output['warnings'] ?? []) as $warning) {
            if (is_string($warning) && trim($warning) !== '') {
                $warnings[] = new AgentWarning(trim($warning));
                continue;
            }
            if (is_array($warning)) {
                $message = $this->string($warning['message'] ?? $warning['label'] ?? null);
                if ($message !== null) {
                    $warnings[] = new AgentWarning($message, $this->string($warning['code'] ?? null));
                }
            }
        }

        $metrics = [];
        foreach ($this->list($output['metrics'] ?? []) as $metric) {
            if (!is_array($metric)) {
                continue;
            }
            $name = $this->string($metric['name'] ?? $metric['label'] ?? null);
            $value = $metric['value'] ?? null;
            if ($name === null || !(is_int($value) || is_float($value) || is_string($value))) {
                continue;
            }
            $metrics[] = new AgentMetric($name, $value, $this->string($metric['unit'] ?? null));
        }

        $entities = [];
        foreach ($this->list($output['entities'] ?? []) as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $type = $this->string($entity['type'] ?? null);
            $id = $this->string($entity['id'] ?? null);
            if ($type === null || $id === null) {
                continue;
            }
            try {
                $entities[] = new EntityRef(strtolower($type), $id);
            } catch (InvalidArgumentException) {
            }
        }

        $evidence = [];
        $rawEvidence = $output['evidence'] ?? [];
        if (is_array($rawEvidence)) {
            if (array_is_list($rawEvidence)) {
                foreach ($rawEvidence as $index => $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $evidence[] = new AgentEvidence('Evidence ' . ($index + 1), trim($item));
                    } elseif (is_array($item)) {
                        $evidence[] = new AgentEvidence('Evidence ' . ($index + 1), $item);
                    }
                }
            } else {
                foreach ($rawEvidence as $label => $item) {
                    if (is_string($item) || is_array($item)) {
                        $evidence[] = new AgentEvidence((string) $label, $item);
                    }
                }
            }
        }

        return new StructuredAgentResult(
            summary: $summary,
            recommendations: $recommendations,
            warnings: $warnings,
            metrics: $metrics,
            entities: $entities,
            evidence: $evidence,
        );
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    private function string(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
