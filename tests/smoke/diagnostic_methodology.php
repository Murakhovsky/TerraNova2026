<?php
declare(strict_types=1);

use Domains\Diagnostic\Methodology\Engine\MethodologyEngine;
use Domains\Diagnostic\Methodology\Engine\DiagnosticRunner;
use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Input\EvidenceSignal;
use Domains\Diagnostic\Methodology\Input\ObservedValue;
use Domains\Diagnostic\Methodology\Loader\PackLoader;
use Domains\Diagnostic\Methodology\Model\DependencyDefinition;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use Domains\Diagnostic\Methodology\Registry\MetricRegistry;
use Domains\Diagnostic\Methodology\Validation\PackValidator;
use Domains\Diagnostic\Model\DiagnosticPack as LifecyclePack;
use Domains\Diagnostic\Model\DiagnosticRecord;
use Domains\Diagnostic\Model\DiagnosticRecordType;
use Domains\Diagnostic\Model\DiagnosticSession;
use Domains\Diagnostic\Model\DiagnosticTarget;
use Domains\Diagnostic\Model\Evidence;
use Domains\Diagnostic\Model\EvidenceType;
use Domains\Diagnostic\Model\Policy\PackPublicationPolicy;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

function methodologyEnsure(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$loader = new PackLoader();
$pack = $loader->load((string) file_get_contents($root . '/tests/fixtures/diagnostic/sales-methodology.json'));
$validation = (new PackValidator())->validate($pack);
methodologyEnsure($validation->isValid(), 'The example methodology pack is invalid.');

$registry = new MetricRegistry($pack->metrics);
methodologyEnsure($registry->has('lead_response_time') && count($registry->all()) === 3, 'Metric registry did not index the pack.');

$now = new DateTimeImmutable('2026-08-30T10:00:00+03:00');
$crm = new EvidenceSignal('crm-export', 'crm_export', 0.95, 1.0, $now->modify('-1 day'));
$accountant = new EvidenceSignal('accountant-report', 'accountant_data', 0.90, 0.95, $now->modify('-5 days'));
$input = new DiagnosticInput(
    ['crm_adopted' => new ObservedValue(true, [$crm])],
    [
        'lead_response_time' => new ObservedValue(75, [$crm]),
        'lost_lead_rate' => new ObservedValue(25, [$crm, $accountant]),
        'lead_count' => new ObservedValue(120, [$crm]),
    ],
    $now,
);
$result = (new MethodologyEngine())->evaluate($input, $pack);
methodologyEnsure($result->score === 20.0, 'Pack score must be deterministic and equal to 20.');
methodologyEnsure($result->coverage->ratio === 1.0 && $result->coverage->level === 'COMPLETE', 'Coverage was calculated incorrectly.');
methodologyEnsure($result->confidence > 0.8 && $result->confidence <= 1.0, 'Confidence was calculated incorrectly.');
methodologyEnsure(count($result->findings) === 1 && $result->findings[0]->severity === 'high', 'Rule did not emit the expected finding.');
methodologyEnsure(count($result->dependencies) === 2, 'Dependency graph was not returned.');

$incomplete = new DiagnosticInput([], [
    'lead_response_time' => new ObservedValue(75, [$crm]),
    'lost_lead_rate' => new ObservedValue(25, [$crm]),
], $now);
$incompleteResult = (new MethodologyEngine())->evaluate($incomplete, $pack);
methodologyEnsure($incompleteResult->score === null, 'An incomplete criterion must not produce a pack score.');
methodologyEnsure($incompleteResult->coverage->level === 'MEDIUM', 'Two of three required inputs must produce MEDIUM coverage.');
methodologyEnsure($incompleteResult->findings === [], 'An incomplete criterion must not emit a finding.');

$cyclic = new MethodologyPack(
    $pack->id, $pack->version, $pack->name, $pack->sections, $pack->criteria, $pack->metrics, $pack->rules, $pack->scoring,
    [
        new DependencyDefinition('lead_response_time', 'lost_lead_rate', 'influences'),
        new DependencyDefinition('lost_lead_rate', 'lead_response_time', 'influences'),
    ],
    $pack->facts,
);
methodologyEnsure(!(new PackValidator())->validate($cyclic)->isValid(), 'A circular dependency was accepted.');

$lifecyclePack = LifecyclePack::draft('Sales', $pack);
$lifecyclePack->publish($now, new PackPublicationPolicy());
$session = new DiagnosticSession('methodology-session', $pack->id, $pack->version, new DiagnosticTarget('Sales', 'organization', 'company-1'));
$session->start($now);
$session->capture(new Evidence('session-crm', EvidenceType::SystemData, 'CRM export', 'crm://export', $now, ['confidence' => 0.95]));
foreach (['lead_response_time' => 75, 'lost_lead_rate' => 25, 'lead_count' => 120] as $metricId => $value) {
    $session->record(new DiagnosticRecord(
        'record-' . $metricId, DiagnosticRecordType::Metric, $metricId, 'Structured metric ' . $metricId,
        $value, null, ['session-crm'], [], $now,
    ), $lifecyclePack);
}
$sessionResult = (new DiagnosticRunner())->evaluate($session, $pack);
methodologyEnsure($sessionResult->score === 20.0 && count($sessionResult->findings) === 1, 'DiagnosticRunner did not evaluate a DiagnosticSession deterministically.');

echo "Diagnostic Methodology Engine passed: validation, registry, rules, scoring, coverage, confidence and dependencies.\n";
