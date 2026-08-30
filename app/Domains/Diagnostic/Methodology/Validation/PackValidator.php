<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Validation;

use Domains\Diagnostic\Methodology\Model\MethodologyPack;

final class PackValidator
{
    private const METRIC_TYPES = ['integer', 'number', 'float', 'boolean', 'string', 'enum', 'percentage', 'ratio', 'duration', 'currency', 'date', 'datetime'];
    private const NUMERIC_TYPES = ['integer', 'number', 'float', 'percentage', 'ratio', 'duration', 'currency'];
    private const OPERATORS = ['==', '=', 'eq', '!=', 'neq', '>', 'gt', '>=', 'gte', '<', 'lt', '<=', 'lte', 'between', 'in', 'exists', 'not_exists'];
    private const DEPENDENCY_TYPES = ['requires', 'influences', 'blocks', 'depends_on', 'contributes_to'];

    public function validate(MethodologyPack $pack): ValidationResult
    {
        $errors = [];
        $warnings = [];
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $pack->id) || trim($pack->name) === '' || $pack->version < 1) {
            $errors[] = $this->issue('pack.invalid_identity', 'pack', 'Pack id/name must not be empty and version must be positive.');
        }

        $sections = $this->index($pack->sections, 'sections', $errors);
        $criteria = $this->index($pack->criteria, 'criteria', $errors);
        $metrics = $this->index($pack->metrics, 'metrics', $errors);
        $facts = $this->index($pack->facts, 'facts', $errors);
        $this->index($pack->rules, 'rules', $errors);
        if ($pack->sections === []) $errors[] = $this->issue('pack.no_sections', 'sections', 'A diagnostic pack requires at least one section.');
        if ($pack->criteria === []) $errors[] = $this->issue('pack.no_criteria', 'criteria', 'A diagnostic pack requires at least one criterion.');
        $globalIds = [];
        foreach (['sections' => $pack->sections, 'criteria' => $pack->criteria, 'metrics' => $pack->metrics, 'facts' => $pack->facts, 'rules' => $pack->rules] as $kind => $items) {
            foreach ($items as $item) {
                if (isset($globalIds[$item->id])) {
                    $errors[] = $this->issue('id.duplicate_global', $kind . '.' . $item->id, 'ID is already used by ' . $globalIds[$item->id] . ': ' . $item->id);
                }
                $globalIds[$item->id] = $kind;
            }
        }

        foreach ($pack->metrics as $metric) {
            if (trim($metric->name) === '') $errors[] = $this->issue('metric.empty_name', 'metrics.' . $metric->id, 'Metric name must not be empty.');
            if (!in_array($metric->type, self::METRIC_TYPES, true)) {
                $errors[] = $this->issue('metric.invalid_type', 'metrics.' . $metric->id, 'Unsupported metric type: ' . $metric->type);
            }
            if (!in_array($metric->direction, ['higher_is_better', 'lower_is_better', 'target_range', 'neutral'], true)) {
                $errors[] = $this->issue('metric.invalid_direction', 'metrics.' . $metric->id, 'Unsupported metric direction: ' . $metric->direction);
            }
            if (!in_array($metric->aggregation, ['average', 'sum', 'minimum', 'maximum', 'latest', 'count'], true)) {
                $errors[] = $this->issue('metric.invalid_aggregation', 'metrics.' . $metric->id, 'Unsupported metric aggregation: ' . $metric->aggregation);
            }
            if ($metric->normalization !== null && !in_array($metric->normalization, ['bands', 'linear', 'inverse_linear'], true)) {
                $errors[] = $this->issue('metric.invalid_normalization', 'metrics.' . $metric->id, 'Unsupported metric normalization: ' . $metric->normalization);
            }
            if ($metric->expectedRange !== null) {
                foreach (['min', 'max'] as $bound) if (array_key_exists($bound, $metric->expectedRange) && !is_numeric($metric->expectedRange[$bound])) {
                    $errors[] = $this->issue('metric.invalid_range', 'metrics.' . $metric->id, 'Expected range bounds must be numeric.');
                }
            }
            if ($metric->expectedRange !== null
                && isset($metric->expectedRange['min'], $metric->expectedRange['max'])
                && (!is_numeric($metric->expectedRange['min']) || !is_numeric($metric->expectedRange['max']) || $metric->expectedRange['min'] > $metric->expectedRange['max'])) {
                $errors[] = $this->issue('metric.invalid_range', 'metrics.' . $metric->id, 'Expected range min must not exceed max.');
            }
        }
        foreach ($pack->facts as $fact) {
            if (trim($fact->name) === '') $errors[] = $this->issue('fact.empty_name', 'facts.' . $fact->id, 'Fact name must not be empty.');
            if (!in_array($fact->type, self::METRIC_TYPES, true)) $errors[] = $this->issue('fact.invalid_type', 'facts.' . $fact->id, 'Unsupported fact type: ' . $fact->type);
            if ($fact->type === 'enum' && $fact->enumValues === []) $errors[] = $this->issue('fact.empty_enum', 'facts.' . $fact->id, 'Enum fact requires enum_values.');
        }
        foreach ($pack->sections as $section) {
            if (trim($section->name) === '') $errors[] = $this->issue('section.empty_name', 'sections.' . $section->id, 'Section name must not be empty.');
            if ($section->weight <= 0) $errors[] = $this->issue('section.invalid_weight', 'sections.' . $section->id, 'Section weight must be positive.');
        }
        foreach ($pack->criteria as $criterion) {
            if (trim($criterion->name) === '') $errors[] = $this->issue('criterion.empty_name', 'criteria.' . $criterion->id, 'Criterion name must not be empty.');
            if (!isset($sections[$criterion->sectionId])) {
                $errors[] = $this->issue('criterion.unknown_section', 'criteria.' . $criterion->id, 'Criterion references unknown section: ' . $criterion->sectionId);
            }
            if ($criterion->minimumCoverage < 0 || $criterion->minimumCoverage > 1
                || $criterion->minimumConfidence < 0 || $criterion->minimumConfidence > 1
                || $criterion->weight <= 0) {
                $errors[] = $this->issue('criterion.invalid_limits', 'criteria.' . $criterion->id, 'Coverage/confidence must be 0..1 and weight must be positive.');
            }
            if ($criterion->required === []) {
                $errors[] = $this->issue('criterion.no_required_input', 'criteria.' . $criterion->id, 'A criterion must declare at least one required evidence-backed input.');
            }
            if (count(array_unique($criterion->required)) !== count($criterion->required)
                || count(array_unique($criterion->optional)) !== count($criterion->optional)
                || array_intersect($criterion->required, $criterion->optional) !== []) {
                $errors[] = $this->issue('criterion.duplicate_input', 'criteria.' . $criterion->id, 'Required and optional inputs must be unique and disjoint.');
            }
            foreach (array_merge($criterion->required, $criterion->optional) as $input) {
                if (!$this->validInputReference($input, $metrics, $facts)) {
                    $errors[] = $this->issue('criterion.unknown_input', 'criteria.' . $criterion->id, 'Criterion references unknown input: ' . $input);
                }
            }
            foreach ($criterion->required as $input) if ($criterion->requiredWeight($input) <= 0) {
                $errors[] = $this->issue('criterion.invalid_input_weight', 'criteria.' . $criterion->id, 'Required input weights must be positive.');
            }
        }
        foreach ($pack->rules as $rule) {
            if (!isset($criteria[$rule->criterionId])) {
                $errors[] = $this->issue('rule.unknown_criterion', 'rules.' . $rule->id, 'Rule references unknown criterion: ' . $rule->criterionId);
            }
            $this->validateConditions($rule->conditions, 'rules.' . $rule->id . '.conditions', $metrics, $facts, $criteria, $errors);
            if (trim($rule->finding) === '') $errors[] = $this->issue('rule.empty_finding', 'rules.' . $rule->id, 'A rule must produce a finding statement.');
            if (!in_array($rule->severity, ['info', 'low', 'medium', 'high', 'critical'], true)) {
                $errors[] = $this->issue('rule.invalid_severity', 'rules.' . $rule->id, 'Unsupported finding severity: ' . $rule->severity);
            }
        }
        $criterionAggregations = [];
        $scoringWeights = [];
        foreach ($pack->scoring as $index => $scoring) {
            $path = 'scoring.' . $index;
            if (!isset($criteria[$scoring->criterionId])) $errors[] = $this->issue('scoring.unknown_criterion', $path, 'Unknown criterion: ' . $scoring->criterionId);
            if (!isset($metrics[$scoring->metricId])) $errors[] = $this->issue('scoring.unknown_metric', $path, 'Unknown metric: ' . $scoring->metricId);
            if (isset($metrics[$scoring->metricId]) && !in_array($metrics[$scoring->metricId]->type, self::NUMERIC_TYPES, true)) {
                $errors[] = $this->issue('scoring.incompatible_metric', $path, 'Score bands require a numeric metric.');
            }
            if (isset($criteria[$scoring->criterionId])
                && !in_array($scoring->metricId, $criteria[$scoring->criterionId]->required, true)
                && !in_array('metric.' . $scoring->metricId, $criteria[$scoring->criterionId]->required, true)
                && !in_array($scoring->metricId, $criteria[$scoring->criterionId]->optional, true)
                && !in_array('metric.' . $scoring->metricId, $criteria[$scoring->criterionId]->optional, true)) {
                $errors[] = $this->issue('scoring.metric_not_input', $path, 'Scoring metric must be an input of its criterion.');
            }
            if ($scoring->minimum < 0 || $scoring->maximum > 100 || $scoring->minimum > $scoring->maximum || $scoring->weight <= 0) $errors[] = $this->issue('scoring.invalid_range', $path, 'Score range must be within 0..100 and weight must be positive.');
            if (!in_array($scoring->normalization, ['bands', 'linear', 'inverse_linear'], true)
                || !in_array($scoring->aggregation, ['weighted_average', 'average', 'sum', 'minimum', 'maximum'], true)) {
                $errors[] = $this->issue('scoring.invalid_strategy', $path, 'Scoring normalization or aggregation strategy is invalid.');
            }
            if ($scoring->normalization !== 'bands'
                && ($scoring->inputMinimum === null || $scoring->inputMaximum === null || $scoring->inputMinimum >= $scoring->inputMaximum)) {
                $errors[] = $this->issue('scoring.invalid_input_range', $path, 'Linear normalization requires input_range.min < input_range.max.');
            }
            if (isset($criterionAggregations[$scoring->criterionId]) && $criterionAggregations[$scoring->criterionId] !== $scoring->aggregation) {
                $errors[] = $this->issue('scoring.mixed_aggregation', $path, 'All scoring definitions for a criterion must use the same aggregation.');
            }
            $criterionAggregations[$scoring->criterionId] = $scoring->aggregation;
            $scoringWeights[$scoring->criterionId][] = $scoring->weight;
            if ($scoring->normalization === 'bands' && $scoring->bands === []) {
                $errors[] = $this->issue('scoring.no_bands', $path, 'Band normalization requires at least one score band.');
            }
            foreach ($scoring->bands as $bandIndex => $band) {
                if (!isset($band['score']) || !is_numeric($band['score']) || $band['score'] < $scoring->minimum || $band['score'] > $scoring->maximum
                    || (isset($band['min']) && !is_numeric($band['min'])) || (isset($band['max']) && !is_numeric($band['max']))
                    || (isset($band['min'], $band['max']) && $band['min'] >= $band['max'])) {
                    $errors[] = $this->issue('scoring.invalid_band', $path . '.bands.' . $bandIndex, 'Band requires a score and a valid optional min/max range.');
                }
            }
            $this->validateBandOverlap($scoring->bands, $path, $errors);
        }
        foreach ($scoringWeights as $criterionId => $weights) {
            if (abs(array_sum($weights) - 1.0) > 0.0001) {
                $warnings[] = $this->issue('scoring.weights_not_normalized', 'scoring.' . $criterionId, 'Scoring weights do not sum to 1; the engine will normalize them.');
            }
        }
        foreach ($pack->sections as $section) {
            $weights = array_map(static fn ($criterion): float => $criterion->weight, array_filter($pack->criteria, static fn ($criterion): bool => $criterion->sectionId === $section->id));
            if ($weights === []) $warnings[] = $this->issue('section.unreachable', 'sections.' . $section->id, 'Section has no criteria and cannot produce a score.');
            if ($weights !== [] && abs(array_sum($weights) - 1.0) > 0.0001) {
                $warnings[] = $this->issue('weights.not_normalized', 'sections.' . $section->id, 'Criterion weights do not sum to 1; the engine will normalize them.');
            }
        }
        if ($pack->sections !== [] && abs(array_sum(array_map(static fn ($section): float => $section->weight, $pack->sections)) - 1.0) > 0.0001) {
            $warnings[] = $this->issue('section.weights_not_normalized', 'sections', 'Section weights do not sum to 1; the engine will normalize them.');
        }
        $nodes = $metrics + $facts + $criteria + $sections;
        $graph = [];
        foreach ($pack->dependencies as $index => $dependency) {
            $sourceNode = $this->nodeId($dependency->source);
            $targetNode = $this->nodeId($dependency->target);
            if (!isset($nodes[$sourceNode]) || !isset($nodes[$targetNode])) {
                $errors[] = $this->issue('dependency.unknown_target', 'dependencies.' . $index, 'Dependency source and target must reference a metric, criterion, or section.');
            }
            if (!in_array($dependency->type, self::DEPENDENCY_TYPES, true) || $dependency->strength < 0 || $dependency->strength > 1
                || !in_array($dependency->direction, ['positive', 'negative', 'neutral'], true)) {
                $errors[] = $this->issue('dependency.invalid', 'dependencies.' . $index, 'Dependency type or strength is invalid.');
            }
            if ($dependency->source === $dependency->target) $errors[] = $this->issue('dependency.self', 'dependencies.' . $index, 'A dependency cannot target itself.');
            $graph[$sourceNode][] = $targetNode;
        }
        if ($this->hasCycle($graph)) {
            $errors[] = $this->issue('dependency.circular', 'dependencies', 'Dependency graph contains a cycle.');
        }

        return new ValidationResult($errors, $warnings);
    }

    private function index(array $items, string $path, array &$errors): array
    {
        $index = [];
        foreach ($items as $position => $item) {
            $id = $item->id ?? '';
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_.-]*$/', $id)) {
                $errors[] = $this->issue('id.invalid', $path . '.' . $position, 'ID must be a stable lowercase identifier.');
            } elseif (isset($index[$id])) {
                $errors[] = $this->issue('id.duplicate', $path . '.' . $position, 'Duplicate ID: ' . $id);
            }
            $index[$id] = $item;
        }
        return $index;
    }

    private function validInputReference(string $input, array $metrics, array $facts): bool
    {
        if (str_starts_with($input, 'fact.')) return isset($facts[substr($input, 5)]);
        return isset($metrics[str_starts_with($input, 'metric.') ? substr($input, 7) : $input]);
    }

    private function validateConditions(array $node, string $path, array $metrics, array $facts, array $criteria, array &$errors): void
    {
        $forms = (int) array_key_exists('all', $node) + (int) array_key_exists('any', $node) + (int) array_key_exists('not', $node)
            + (int) (array_key_exists('subject', $node) || array_key_exists('field', $node));
        if ($forms !== 1) {
            $errors[] = $this->issue('rule.ambiguous_condition', $path, 'Condition must contain exactly one of all, any, not, or subject.');
            return;
        }
        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $node)) {
                if (!is_array($node[$group]) || $node[$group] === [] || !array_is_list($node[$group])) {
                    $errors[] = $this->issue('rule.invalid_group', $path, $group . ' must be a non-empty list.');
                    return;
                }
                foreach ($node[$group] as $index => $child) {
                    if (!is_array($child)) $errors[] = $this->issue('rule.invalid_condition', $path . '.' . $group . '.' . $index, 'Condition must be an object.');
                    else $this->validateConditions($child, $path . '.' . $group . '.' . $index, $metrics, $facts, $criteria, $errors);
                }
                return;
            }
        }
        if (array_key_exists('not', $node)) {
            if (!is_array($node['not']) || $node['not'] === []) $errors[] = $this->issue('rule.invalid_not', $path, 'not must contain one condition object.');
            else $this->validateConditions($node['not'], $path . '.not', $metrics, $facts, $criteria, $errors);
            return;
        }
        $subject = (string) ($node['subject'] ?? $node['field'] ?? '');
        $operator = strtolower((string) ($node['operator'] ?? ''));
        if (!in_array($operator, self::OPERATORS, true)) $errors[] = $this->issue('rule.invalid_operator', $path, 'Unsupported operator: ' . $operator);
        $assessmentPrefix = str_starts_with($subject, 'assessment.') ? 'assessment.' : (str_starts_with($subject, 'criterion.') ? 'criterion.' : null);
        $assessmentParts = $assessmentPrefix !== null ? explode('.', substr($subject, strlen($assessmentPrefix)), 2) : [];
        $assessmentField = $assessmentParts[1] ?? null;
        $validAssessment = isset($assessmentParts[0], $criteria[$assessmentParts[0]]) && in_array($assessmentField, ['score', 'confidence', 'coverage'], true);
        $metricId = str_starts_with($subject, 'metric.') ? substr($subject, 7) : $subject;
        $validFact = str_starts_with($subject, 'fact.') && isset($facts[substr($subject, 5)]);
        if (!$validFact && !isset($metrics[$metricId]) && !$validAssessment) {
            $errors[] = $this->issue('rule.unknown_subject', $path, 'Unknown condition subject: ' . $subject);
        }
        $expected = $node['value'] ?? $node['values'] ?? null;
        if (in_array($operator, ['between'], true) && (!is_array($expected) || count($expected) !== 2 || !is_numeric($expected[0] ?? null) || !is_numeric($expected[1] ?? null) || $expected[0] > $expected[1])) {
            $errors[] = $this->issue('rule.invalid_between', $path, 'between requires two ordered numeric values.');
        }
        if ($operator === 'in' && (!is_array($expected) || $expected === [])) $errors[] = $this->issue('rule.invalid_in', $path, 'in requires a non-empty value list.');
        if (!in_array($operator, ['exists', 'not_exists', 'between', 'in'], true) && !array_key_exists('value', $node)) {
            $errors[] = $this->issue('rule.missing_value', $path, 'Comparison operator requires a value.');
        }
        if (isset($metrics[$metricId]) && in_array($operator, ['>', 'gt', '>=', 'gte', '<', 'lt', '<=', 'lte', 'between'], true)
            && !in_array($metrics[$metricId]->type, self::NUMERIC_TYPES, true)) {
            $errors[] = $this->issue('rule.incompatible_operator', $path, 'Ordering operators require a numeric metric.');
        }
    }

    private function validateBandOverlap(array $bands, string $path, array &$errors): void
    {
        $ranges = [];
        foreach ($bands as $index => $band) {
            if (!is_array($band) || !isset($band['score'])) continue;
            $ranges[] = ['min' => isset($band['min']) ? (float) $band['min'] : -INF, 'max' => isset($band['max']) ? (float) $band['max'] : INF, 'index' => $index];
        }
        usort($ranges, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i]['min'] < $ranges[$i - 1]['max']) {
                $errors[] = $this->issue('scoring.overlapping_bands', $path . '.bands.' . $ranges[$i]['index'], 'Score bands must not overlap.');
            }
        }
    }

    private function nodeId(string $reference): string
    {
        foreach (['fact.', 'metric.', 'criterion.', 'area.', 'section.'] as $prefix) {
            if (str_starts_with($reference, $prefix)) return substr($reference, strlen($prefix));
        }
        return $reference;
    }

    private function hasCycle(array $graph): bool
    {
        $visiting = []; $visited = [];
        $visit = function (string $node) use (&$visit, &$visiting, &$visited, $graph): bool {
            if (isset($visiting[$node])) return true;
            if (isset($visited[$node])) return false;
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $target) if ($visit($target)) return true;
            unset($visiting[$node]); $visited[$node] = true; return false;
        };
        foreach (array_keys($graph) as $node) if ($visit($node)) return true;
        return false;
    }

    private function issue(string $code, string $path, string $message): ValidationIssue
    {
        return new ValidationIssue($code, $path, $message);
    }
}
