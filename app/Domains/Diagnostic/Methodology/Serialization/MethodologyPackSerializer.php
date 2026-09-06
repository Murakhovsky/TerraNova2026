<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Serialization;

use Domains\Diagnostic\Methodology\Loader\PackLoader;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use JsonException;
use RuntimeException;

final class MethodologyPackSerializer
{
    /** @return array<string, mixed> */
    public function toArray(MethodologyPack $pack): array
    {
        return [
            'id' => $pack->id,
            'version' => $pack->version,
            'name' => $pack->name,
            'sections' => array_map(static fn ($item): array => [
                'id' => $item->id, 'name' => $item->name, 'weight' => $item->weight,
            ], $pack->sections),
            'criteria' => array_map(static fn ($item): array => [
                'id' => $item->id, 'name' => $item->name, 'section' => $item->sectionId,
                'required' => array_map(static fn (string $ref): array => ['ref' => $ref, 'weight' => $item->requiredWeight($ref)], $item->required),
                'optional' => array_map(static fn (string $ref): array => ['ref' => $ref, 'weight' => $item->optionalWeights[$ref] ?? 1.0], $item->optional),
                'weight' => $item->weight, 'minimum_coverage' => $item->minimumCoverage,
                'minimum_confidence' => $item->minimumConfidence,
                'applicability' => $item->applicability, 'good_practices' => $item->goodPractices,
                'bad_practices' => $item->badPractices, 'description' => $item->description,
                'importance' => $item->importance,
            ], $pack->criteria),
            'metrics' => array_map(static fn ($item): array => [
                'id' => $item->id, 'name' => $item->name, 'type' => $item->type,
                'unit' => $item->unit, 'direction' => $item->direction,
                'normalization' => $item->normalization, 'expected_range' => $item->expectedRange,
                'aggregation' => $item->aggregation,
                'formula_expression' => $item->formula, 'input_facts' => $item->inputFacts,
                'time_window' => $item->timeWindow, 'minimum_sample_size' => $item->minimumSampleSize,
                'benchmark_reference' => $item->benchmarkReference,
            ], $pack->metrics),
            'facts' => array_map(static fn ($item): array => [
                'id' => $item->id, 'name' => $item->name, 'type' => $item->type,
                'unit' => $item->unit, 'enum_values' => $item->enumValues,
            ], $pack->facts),
            'rules' => array_map(static fn ($item): array => [
                'id' => $item->id, 'criterion' => $item->criterionId,
                'conditions' => $item->conditions, 'finding' => $item->finding, 'severity' => $item->severity,
            ], $pack->rules),
            'scoring' => array_map(static fn ($item): array => [
                'criterion' => $item->criterionId, 'metric' => $item->metricId, 'bands' => $item->bands,
                'weight' => $item->weight, 'penalty' => $item->penalty, 'bonus' => $item->bonus,
                'min' => $item->minimum, 'max' => $item->maximum,
                'normalization' => $item->normalization, 'aggregation' => $item->aggregation,
                'input_range' => ['min' => $item->inputMinimum, 'max' => $item->inputMaximum],
            ], $pack->scoring),
            'dependencies' => array_map(static fn ($item): array => [
                'source' => $item->source, 'target' => $item->target, 'type' => $item->type,
                'strength' => $item->strength, 'direction' => $item->direction,
            ], $pack->dependencies),
            'questions' => array_map(static fn ($item): array => [
                'id' => $item->id, 'text' => $item->text, 'target_facts' => $item->targetFacts,
                'target_criteria' => $item->targetCriteria, 'priority' => $item->priority,
                'expected_answer_type' => $item->expectedAnswerType,
                'follow_up_conditions' => $item->followUpConditions,
                'evidence_requirement_id' => $item->evidenceRequirementId,
                'cost' => $item->cost, 'area_id' => $item->areaId,
                'allowed_diagnostic_modes' => $item->allowedDiagnosticModes, 'enabled' => $item->enabled,
            ], $pack->questions),
            'evidence_requirements' => array_map(static fn ($item): array => [
                'id' => $item->id, 'criteria' => $item->criterionIds,
                'accepted_source_types' => $item->acceptedSourceTypes, 'hierarchy' => $item->hierarchy,
                'minimum_sources' => $item->minimumSources, 'minimum_reliability' => $item->minimumReliability,
                'minimum_directness' => $item->minimumDirectness, 'required' => $item->required,
                'minimum_sample_size' => $item->minimumSampleSize, 'maximum_age' => $item->maximumAge,
                'direct_evidence_required' => $item->directEvidenceRequired,
            ], $pack->evidenceRequirements),
            'recommendations' => array_map(static fn ($item): array => [
                'id' => $item->id, 'trigger_rules' => $item->triggerRules,
                'criteria' => $item->criterionIds, 'target_problem' => $item->targetProblem,
                'title' => $item->title, 'rationale' => $item->rationale, 'actions' => $item->actions,
                'expected_impact' => $item->expectedImpact, 'implementation_effort' => $item->implementationEffort,
                'priority' => $item->priority, 'success_metrics' => $item->successMetrics,
                'dependencies' => $item->dependencies, 'owner_role' => $item->ownerRole,
                'description' => $item->description, 'target_findings' => $item->targetFindings,
                'cost_level' => $item->costLevel, 'implementation_time' => $item->implementationTime,
            ], $pack->recommendations),
            'benchmarks'=>array_map(static fn($item):array=>['id'=>$item->id,'metric_id'=>$item->metricId,'name'=>$item->name,'segments'=>$item->segments,'bands'=>$item->bands,'unit'=>$item->unit,'source'=>$item->source,'valid_from'=>$item->validFrom,'valid_to'=>$item->validTo],$pack->benchmarks),
        ];
    }

    public function fromArray(array $data): MethodologyPack
    {
        return (new PackLoader())->load($data);
    }

    public function encode(MethodologyPack $pack): string
    {
        try {
            return json_encode($this->canonical($this->toArray($pack)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to serialize diagnostic methodology.', 0, $exception);
        }
    }

    public function decode(string $json): MethodologyPack
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to deserialize diagnostic methodology.', 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Diagnostic methodology payload must be an object.');
        }
        return $this->fromArray($data);
    }

    public function hash(MethodologyPack $pack): string
    {
        return hash('sha256', $this->encode($pack));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->canonical(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);
        return $value;
    }
}
