<?php
declare(strict_types=1);

use Domains\Diagnostic\Methodology\Engine\ConfidenceEngine;
use Domains\Diagnostic\Methodology\Engine\CoverageEngine;
use Domains\Diagnostic\Methodology\Engine\DependencyEngine;
use Domains\Diagnostic\Methodology\Engine\MethodologyEngine;
use Domains\Diagnostic\Methodology\Engine\RuleEngine;
use Domains\Diagnostic\Methodology\Engine\ScoringEngine;
use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Input\EvidenceSignal;
use Domains\Diagnostic\Methodology\Input\ObservedValue;
use Domains\Diagnostic\Methodology\Loader\PackLoader;
use Domains\Diagnostic\Methodology\Model\RuleDefinition;
use Domains\Diagnostic\Methodology\Model\ScoringDefinition;
use Domains\Diagnostic\Methodology\Model\CriterionDefinition;
use Domains\Diagnostic\Methodology\Model\SectionDefinition;
use Domains\Diagnostic\Methodology\Registry\MetricRegistry;
use Domains\Diagnostic\Methodology\Serialization\MethodologyPackSerializer;
use Domains\Diagnostic\Methodology\Validation\PackValidator;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

function methodologyContractEnsure(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function methodologyContractThrows(callable $operation, string $message): void
{
    try { $operation(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($message);
}

function methodologyValidationCodes(array $data): array
{
    $result = (new PackValidator())->validate((new PackLoader())->load($data));
    return array_map(static fn ($issue): string => $issue->code, $result->errors);
}

$loader = new PackLoader();
$serializer = new MethodologyPackSerializer();
$jsonPack = $loader->load($root . '/tests/fixtures/diagnostic/sales-methodology.json');
$yamlPack = $loader->load($root . '/tests/fixtures/diagnostic/sales-methodology.yaml');
methodologyContractEnsure($serializer->hash($jsonPack) === $serializer->hash($yamlPack), 'JSON and YAML must compile to identical methodology content.');
methodologyContractThrows(
    static fn () => (new PackLoader())->load("id:\n\tbad: indentation"),
    'Unsafe/invalid YAML indentation was accepted.',
);

$base = $serializer->toArray($jsonPack);
$unknown = $base;
$unknown['criteria'][0]['required'][0]['ref'] = 'metric.missing';
methodologyContractEnsure(in_array('criterion.unknown_input', methodologyValidationCodes($unknown), true), 'Unknown criterion input was accepted.');

$duplicate = $base;
$duplicate['metrics'][] = $duplicate['metrics'][0];
methodologyContractEnsure(in_array('id.duplicate', methodologyValidationCodes($duplicate), true), 'Duplicate metric ID was accepted.');

$invalidRule = $base;
$invalidRule['rules'][0]['conditions'] = ['all' => []];
methodologyContractEnsure(in_array('rule.invalid_group', methodologyValidationCodes($invalidRule), true), 'Empty AND group was accepted.');

$overlap = $base;
$overlap['scoring'][0]['bands'][1]['min'] = 4;
methodologyContractEnsure(in_array('scoring.overlapping_bands', methodologyValidationCodes($overlap), true), 'Overlapping score bands were accepted.');

$selfDependency = $base;
$selfDependency['dependencies'][0]['target'] = $selfDependency['dependencies'][0]['source'];
methodologyContractEnsure(in_array('dependency.self', methodologyValidationCodes($selfDependency), true), 'Self dependency was accepted.');

$weighted = $base;
$weighted['criteria'][0]['required'] = [
    ['ref' => 'lead_response_time', 'weight' => 3],
    ['ref' => 'lost_lead_rate', 'weight' => 1],
    ['ref' => 'lead_count', 'weight' => 1],
];
$weightedPack = $loader->load($weighted);
$now = new DateTimeImmutable('2026-08-30T10:00:00+03:00');
$trusted = new EvidenceSignal('trusted', 'crm_export', 0.95, 1.0, $now, 75);
$partialInput = new DiagnosticInput([], [
    'lead_response_time' => new ObservedValue(75, [$trusted]),
    'lost_lead_rate' => new ObservedValue(25, [$trusted]),
], $now);
$weightedCoverage = (new CoverageEngine())->evaluate($weightedPack->criteria[0], $partialInput);
methodologyContractEnsure($weightedCoverage->ratio === 0.8 && $weightedCoverage->level === 'HIGH', 'Coverage does not honor required-input weights.');

$lowConfidenceData = $base;
$lowConfidenceData['criteria'][0]['minimum_confidence'] = 0.9;
$lowConfidencePack = $loader->load($lowConfidenceData);
$weak = new EvidenceSignal('weak', 'owner_estimate', 0.4, 1.0, $now);
$completeWeakInput = new DiagnosticInput(
    ['crm_adopted' => new ObservedValue(true, [$weak])],
    [
        'lead_response_time' => new ObservedValue(75, [$weak]),
        'lost_lead_rate' => new ObservedValue(25, [$weak]),
        'lead_count' => new ObservedValue(120, [$weak]),
    ],
    $now,
);
$weakResult = (new MethodologyEngine())->evaluate($completeWeakInput, $lowConfidencePack);
methodologyContractEnsure($weakResult->score === null && $weakResult->findings === [], 'Low confidence did not suppress conclusions.');

$ruleInput = new DiagnosticInput(
    ['crm_adopted' => new ObservedValue(true, [$trusted])],
    [
        'lead_response_time' => new ObservedValue(75, [$trusted]),
        'lost_lead_rate' => new ObservedValue(25, [$trusted]),
        'lead_count' => new ObservedValue(120, [$trusted]),
    ],
    $now,
);
$conditions = [
    ['subject' => 'metric.lead_count', 'operator' => '==', 'value' => 120],
    ['subject' => 'metric.lead_count', 'operator' => '!=', 'value' => 121],
    ['subject' => 'metric.lost_lead_rate', 'operator' => '>', 'value' => 20],
    ['subject' => 'metric.lost_lead_rate', 'operator' => '>=', 'value' => 25],
    ['subject' => 'metric.lead_response_time', 'operator' => '<', 'value' => 100],
    ['subject' => 'metric.lead_response_time', 'operator' => '<=', 'value' => 75],
    ['subject' => 'metric.lead_response_time', 'operator' => 'between', 'value' => [70, 80]],
    ['subject' => 'metric.lead_response_time', 'operator' => 'in', 'value' => [60, 75]],
    ['subject' => 'fact.crm_adopted', 'operator' => 'exists'],
    ['subject' => 'fact.sales_playbook', 'operator' => 'not_exists'],
    ['any' => [
        ['subject' => 'metric.lead_count', 'operator' => '==', 'value' => 0],
        ['subject' => 'metric.lead_count', 'operator' => '==', 'value' => 120],
    ]],
    ['not' => ['subject' => 'metric.lead_count', 'operator' => '==', 'value' => 0]],
];
$operatorRules = array_map(
    static fn (array $condition, int $index): RuleDefinition => new RuleDefinition('operator-' . $index, 'lead-processing', $condition, 'matched', 'info'),
    $conditions,
    array_keys($conditions),
);
methodologyContractEnsure(count((new RuleEngine())->evaluate($operatorRules, $ruleInput)) === count($conditions), 'Not all required rule operators/combinators matched deterministically.');

$linear = new ScoringDefinition('lead-processing', 'lead_response_time', [], 1, 5, 2, 0, 100, 'linear', 'weighted_average', 0, 100);
methodologyContractEnsure((new ScoringEngine())->criterionScore('lead-processing', $ruleInput, [$linear]) === 72.0, 'Linear normalization, penalty or bonus is incorrect.');
$blockedSection = (new ScoringEngine())->sectionScores(
    ['available' => 80.0, 'missing' => null],
    [
        new CriterionDefinition('available', 'Available', 'section', [], [], 0.5),
        new CriterionDefinition('missing', 'Missing', 'section', [], [], 0.5),
    ],
    [new SectionDefinition('section', 'Section')],
);
methodologyContractEnsure($blockedSection['section'] === null, 'Section score ignored a criterion without a defensible score.');

$alignedInput = new DiagnosticInput([], [
    'lead_response_time' => new ObservedValue(75, [
        new EvidenceSignal('aligned-a', 'crm', 0.9, 1, $now, 75),
        new EvidenceSignal('aligned-b', 'accounting', 0.9, 1, $now, 75),
    ]),
    'lost_lead_rate' => new ObservedValue(25),
    'lead_count' => new ObservedValue(120),
], $now);
$conflictingInput = new DiagnosticInput([], [
    'lead_response_time' => new ObservedValue(75, [
        new EvidenceSignal('conflict-a', 'crm', 0.9, 1, $now, 10),
        new EvidenceSignal('conflict-b', 'accounting', 0.9, 1, $now, 90),
    ]),
    'lost_lead_rate' => new ObservedValue(25),
    'lead_count' => new ObservedValue(120),
], $now);
$confidenceEngine = new ConfidenceEngine();
methodologyContractEnsure(
    $confidenceEngine->evaluate($jsonPack->criteria[0], $alignedInput) > $confidenceEngine->evaluate($jsonPack->criteria[0], $conflictingInput),
    'Source agreement does not affect confidence.',
);

$linearData = $base;
$linearData['scoring'][0]['bands'] = [];
$linearData['scoring'][0]['normalization'] = 'inverse_linear';
$linearData['scoring'][0]['aggregation'] = 'weighted_average';
$linearData['scoring'][0]['input_range'] = ['min' => 0, 'max' => 100];
$linearPack = $loader->load($linearData);
$roundTrip = $serializer->decode($serializer->encode($linearPack));
methodologyContractEnsure($serializer->hash($linearPack) === $serializer->hash($roundTrip), 'Canonical serialization loses scoring behavior.');
$reorderedData = $base;
$leaf = $reorderedData['rules'][0]['conditions']['all'][0];
$reorderedData['rules'][0]['conditions']['all'][0] = ['value' => $leaf['value'], 'operator' => $leaf['operator'], 'subject' => $leaf['subject']];
methodologyContractEnsure(
    $serializer->hash($jsonPack) === $serializer->hash($loader->load($reorderedData)),
    'Canonical hash depends on mapping key order.',
);

$registry = new MetricRegistry($jsonPack->metrics);
methodologyContractThrows(static fn () => $registry->get('missing'), 'MetricRegistry did not reject an unknown metric.');
$dependencyEngine = new DependencyEngine();
methodologyContractEnsure(
    count($dependencyEngine->downstream('lead_response_time', $jsonPack->dependencies)) === 1
    && count($dependencyEngine->upstream('lead-management', $jsonPack->dependencies)) === 1,
    'Dependency traversal did not expose structural upstream/downstream links.',
);

echo "Diagnostic methodology contract passed: JSON/YAML, validation, weights, confidence, operators, scoring and canonical hashing.\n";
