<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Loader;

use Domains\Diagnostic\Methodology\Model\CriterionDefinition;
use Domains\Diagnostic\Methodology\Model\DependencyDefinition;
use Domains\Diagnostic\Methodology\Model\FactDefinition;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use Domains\Diagnostic\Methodology\Model\MetricDefinition;
use Domains\Diagnostic\Methodology\Model\RuleDefinition;
use Domains\Diagnostic\Methodology\Model\ScoringDefinition;
use Domains\Diagnostic\Methodology\Model\SectionDefinition;
use InvalidArgumentException;
use JsonException;

final class PackLoader
{
    /** @param array<string,mixed>|string $source */
    public function load(array|string $source): MethodologyPack
    {
        $data = is_array($source) ? $source : $this->decode($source);

        return new MethodologyPack(
            (string) ($data['id'] ?? ''),
            (int) ($data['version'] ?? 1),
            (string) ($data['name'] ?? ''),
            array_map(fn (array $item): SectionDefinition => new SectionDefinition(
                (string) ($item['id'] ?? ''), (string) ($item['name'] ?? ''), (float) ($item['weight'] ?? 1),
            ), $this->list($data, 'sections', 'areas')),
            array_map(function (array $item): CriterionDefinition {
                [$required, $requiredWeights] = $this->references($item['required'] ?? $item['required_inputs'] ?? []);
                [$optional, $optionalWeights] = $this->references($item['optional'] ?? $item['optional_inputs'] ?? []);
                return new CriterionDefinition(
                    (string) (0['id'] ?? ''),
                    (string) ($item['name'] ?? ''),
                    (string) ($item['section'] ?? $item['section_id'] ?? $item['area_id'] ?? ''),
                    $required,
                    $optional,
                    (float) ($item['weight'] ?? 1),
                    (float) ($item['minimum_coverage'] ?? 1),
                    (float) ($item['minimum_confidence'] ?? 0),
                    $requiredWeights,
                    $optionalWeights,
                );
            }, $this->list($data, 'criteria')),
            array_map(fn (array $item): MetricDefinition => new MetricDefinition(
                (string) ($item['id'] ?? ''),
                (string) ($item['name'] ?? ''),
                (string) ($item['type'] ?? $item['value_type'] ?? ''),
                isset($item['unit']) ? (string) $item['unit'] : null,
                (string) ($item['direction'] ?? 'neutral'),
                isset($item['normalization']) ? (string) $item['normalization'] : null,
                isset($item['expected_range']) && is_array($item['expected_range']) ? $item['expected_range'] : null,
                (string) ($item['aggregation'] ?? 'average'),
            ), $this->list($data, 'metrics')),
            array_map(fn (array $item): RuleDefinition => new RuleDefinition(
                (string) ($item['id'] ?? ''),
                (string) ($item['criterion'] ?? $item['criterion_id'] ?? ''),
                is_array($item['conditions'] ?? $item['when'] ?? null) ? ($item['conditions'] ?? $item['when']) : [],
                (string) ($item['finding'] ?? $item['then']['statement'] ?? ''),
                (string) ($item['severity'] ?? $item['then']['severity'] ?? ''),
            ), $this->list($data, 'rules')),
            array_map(fn (array $item): ScoringDefinition => new ScoringDefinition(
                (string) ($item['criterion'] ?? $item['criterion_id'] ?? ''),
                (string) ($item['metric'] ?? $item['metric_id'] ?? ''),
                array_values($item['bands'] ?? []),
                (float) ($item['weight'] ?? 1),
                (float) ($item['penalty'] ?? 0),
                (float) ($item['bonus'] ?? 0),
                (float) ($item['min'] ?? 0),
                (float) ($item['max'] ?? 100),
                (string) ($item['normalization'] ?? 'bands'),
                (string) ($item['aggregation'] ?? 'weighted_average'),
                isset($item['input_range']['min']) ? (float) $item['input_range']['min'] : null,
                isset($item['input_range']['max']) ? (float) $item['input_range']['max'] : null,
            ), $this->list($data, 'scoring')),
            array_map(fn (array $item): DependencyDefinition => new DependencyDefinition(
                (string) ($item['source'] ?? ''),
                (string) ($item['target'] ?? ''),
                (string) ($item['type'] ?? ''),
                (float) ($item['strength'] ?? 1),
                (string) ($item['direction'] ?? 'positive'),
            ), $this->list($data, 'dependencies')),
            array_map(fn (array $item): FactDefinition => new FactDefinition(
                (string) ($item['id'] ?? ''),
                (string) ($item['name'] ?? ''),
                (string) ($item['type'] ?? $item['value_type'] ?? ''),
                isset($item['unit']) ? (string) $item['unit'] : null,
                array_values($item['enum_values'] ?? []),
            ), $this->list($data, 'facts')),
        );
    }

    /** @return array<string,mixed> */
    private function decode(string $source): array
    {
        $format = null;
        if (is_file($source)) {
            $format = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
            $contents = file_get_contents($source);
            if ($contents === false) throw new InvalidArgumentException('Unable to read diagnostic pack: ' . $source);
            $source = $contents;
        }
        $trimmed = ltrim($source);
        if ($format === 'json' || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            try {
                $decoded = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Invalid diagnostic pack JSON: ' . $exception->getMessage(), 0, $exception);
            }
        } else {
            $decoded = function_exists('yaml_parse') ? yaml_parse($source) : (new SimpleYamlDecoder())->decode($source);
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Diagnostic pack root must be an object.');
        }
        return $decoded;
    }

    /** @return list<array<string,mixed>> */
    private function list(array $data, string $key, ?string $alias = null): array
    {
        $value = $data[$key] ?? ($alias !== null ? ($data[$alias] ?? []) : []);
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Diagnostic pack field "%s" must be a list.', $key));
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(sprintf('Every "%s" item must be an object.', $key));
            }
        }
        return array_values($value);
    }

    /** @return array{list<string>,array<string,float>} */
    private function references(mixed $value): array
    {
        if (!is_array($value)) throw new InvalidArgumentException('Criterion input references must be a list.');
        $references = []; $weights = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $reference = $item; $weight = 1.0;
            } elseif (is_array($item) && is_string($item['ref'] ?? null)) {
                $reference = $item['ref']; $weight = (float) ($item['weight'] ?? 1);
            } else {
                throw new InvalidArgumentException('Criterion input must be a reference string or {ref, weight}.');
            }
            $references[] = $reference;
            $weights[$reference] = $weight;
        }
        return [$references, $weights];
    }
}
