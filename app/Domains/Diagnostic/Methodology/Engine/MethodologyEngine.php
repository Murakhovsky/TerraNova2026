<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use Domains\Diagnostic\Methodology\Result\Coverage;
use Domains\Diagnostic\Methodology\Result\CriterionAssessment;
use Domains\Diagnostic\Methodology\Result\DiagnosticResult;
use Domains\Diagnostic\Methodology\Validation\PackValidator;
use InvalidArgumentException;

class MethodologyEngine
{
    public function __construct(
        private readonly PackValidator $validator = new PackValidator(),
        private readonly CoverageEngine $coverageEngine = new CoverageEngine(),
        private readonly ConfidenceEngine $confidenceEngine = new ConfidenceEngine(),
        private readonly RuleEngine $ruleEngine = new RuleEngine(),
        private readonly ScoringEngine $scoringEngine = new ScoringEngine(),
        private readonly DependencyEngine $dependencyEngine = new DependencyEngine(),
    ) {
    }

    public function evaluate(DiagnosticInput $input, MethodologyPack $pack): DiagnosticResult
    {
        $validation = $this->validator->validate($pack);
        if (!$validation->isValid()) {
            throw new InvalidArgumentException('Invalid diagnostic pack: ' . implode('; ', array_map(static fn ($issue): string => $issue->path . ': ' . $issue->message, $validation->errors)));
        }
        $this->assertCompatibleInput($input, $pack);

        $assessments = []; $criterionScores = [];
        foreach ($pack->criteria as $criterion) {
            $coverage = $this->coverageEngine->evaluate($criterion, $input);
            $criterionConfidence = $this->confidenceEngine->evaluate($criterion, $input);
            $eligible = $coverage->ratio >= $criterion->minimumCoverage
                && $criterionConfidence >= $criterion->minimumConfidence;
            // A score over observed applicable inputs is provisional; coverage/confidence
            // gate conclusions, not the availability of that partial score.
            $score = $this->scoringEngine->criterionScore($criterion->id, $input, $pack->scoring);
            $criterionScores[$criterion->id] = $score;
            $evidenceIds = $input->evidenceIds(array_merge($criterion->required, $criterion->optional));
            $assessments[$criterion->id] = new CriterionAssessment(
                $criterion->id, $score, $coverage, $criterionConfidence, [], $evidenceIds,
            );
        }

        $eligibleRules = array_values(array_filter(
            $pack->rules,
            static function ($rule) use ($assessments, $pack): bool {
                $assessment = $assessments[$rule->criterionId] ?? null;
                if ($assessment === null) return false;
                foreach ($pack->criteria as $criterion) {
                    if ($criterion->id === $rule->criterionId) {
                        return $assessment->coverage->ratio >= $criterion->minimumCoverage
                            && $assessment->confidence >= $criterion->minimumConfidence;
                    }
                }
                return false;
            },
        ));
        $findings = $this->ruleEngine->evaluate($eligibleRules, $input, $assessments);
        foreach ($assessments as $id => $assessment) {
            $criterionFindings = array_values(array_filter($findings, static fn ($finding): bool => $finding->criterionId === $id));
            $assessments[$id] = new CriterionAssessment(
                $id, $assessment->score, $assessment->coverage, $assessment->confidence,
                $criterionFindings, $assessment->evidenceIds,
            );
        }
        $sectionScores = $this->scoringEngine->sectionScores($criterionScores, $pack->criteria, $pack->sections);
        $criterionWeights = [];
        foreach ($pack->criteria as $criterion) $criterionWeights[$criterion->id] = $criterion->weight;
        $totalWeight = array_sum($criterionWeights);
        $coverageRatio = $totalWeight > 0
            ? array_sum(array_map(static fn ($assessment): float => $assessment->coverage->ratio * $criterionWeights[$assessment->criterionId], $assessments)) / $totalWeight
            : 1.0;
        $confidence = $totalWeight > 0
            ? array_sum(array_map(static fn ($assessment): float => $assessment->confidence * $criterionWeights[$assessment->criterionId], $assessments)) / $totalWeight
            : 0.0;
        $availableNodes = array_fill_keys(array_merge(array_keys($input->metrics), array_keys($assessments), array_keys($sectionScores)), true);

        return new DiagnosticResult(
            $pack->id,
            $pack->version,
            $assessments,
            $sectionScores,
            $this->scoringEngine->packScore($sectionScores, $pack->sections),
            new Coverage(round($coverageRatio, 4), $this->coverageEngine->level($coverageRatio)),
            round($confidence, 4),
            $findings,
            $this->dependencyEngine->evaluate($pack->dependencies, $availableNodes),
        );
    }

    private function assertCompatibleInput(DiagnosticInput $input, MethodologyPack $pack): void
    {
        $definitions = [];
        foreach ($pack->metrics as $metric) $definitions[$metric->id] = $metric;
        foreach ($input->metrics as $id => $observed) {
            $definition = $definitions[$id] ?? throw new InvalidArgumentException('Input contains an unknown metric: ' . $id);
            $compatible = $this->valueMatchesType($observed->value, $definition->type);
            if (!$compatible) throw new InvalidArgumentException(sprintf('Metric %s expects %s, %s given.', $id, $definition->type, get_debug_type($observed->value)));
        }
        $factDefinitions = [];
        foreach ($pack->facts as $fact) $factDefinitions[$fact->id] = $fact;
        foreach ($input->facts as $id => $observed) {
            $definition = $factDefinitions[$id] ?? throw new InvalidArgumentException('Input contains an unknown fact: ' . $id);
            if (!$this->valueMatchesType($observed->value, $definition->type)) {
                throw new InvalidArgumentException(sprintf('Fact %s expects %s, %s given.', $id, $definition->type, get_debug_type($observed->value)));
            }
            if ($definition->type === 'enum' && !in_array($observed->value, $definition->enumValues, true)) {
                throw new InvalidArgumentException('Fact ' . $id . ' contains a value outside enum_values.');
            }
        }
    }

    private function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'integer' => is_int($value),
            'number', 'float', 'percentage', 'ratio', 'duration', 'currency' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'string', 'enum', 'date', 'datetime' => is_string($value),
            default => false,
        };
    }
}
