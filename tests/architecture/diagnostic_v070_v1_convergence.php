<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$manifest = require $root . '/app/Domains/Diagnostic/module.php';
$assert(($manifest['version'] ?? null) === '0.7.0', 'Diagnostic V0.7 manifest version missing.');
$assert(($manifest['schema_version'] ?? null) === '0.7.0', 'Diagnostic V0.7 schema version missing.');
foreach (['diagnostic.methodology.compile','diagnostic.state.rebuild','diagnostic.traceability','diagnostic.semantic.v1'] as $capability) {
    $assert(in_array($capability, $manifest['contributions']['capabilities'] ?? [], true), 'Missing Diagnostic capability: ' . $capability);
}

foreach ([
    'app/Domains/Diagnostic/Model/TruthLevel.php' => ['OBSERVED','REPORTED','CALCULATED','DERIVED','INFERRED','ESTIMATED','ASSUMED'],
    'app/Domains/Diagnostic/Model/AssessmentStatus.php' => ['NOT_STARTED','INSUFFICIENT_DATA','ASSESSED','GOOD','WARNING','CRITICAL','NOT_APPLICABLE','CONTRADICTORY'],
    'app/Domains/Diagnostic/Model/HypothesisStatus.php' => ['UNVERIFIED','SUPPORTED','STRONGLY_SUPPORTED','CONFIRMED_ROOT_CAUSE','REJECTED'],
    'app/Domains/Diagnostic/Model/Severity.php' => ['NONE','INFO','LOW','MEDIUM','HIGH','CRITICAL'],
] as $path => $needles) {
    $source = $read($path);
    foreach ($needles as $needle) $assert(str_contains($source, $needle), $path . ' missing normative value: ' . $needle);
}

$assessment = $read('app/Domains/Diagnostic/Model/Assessment.php');
foreach (['Severity $severity', 'float $coverage', 'float $confidence', '?float $score'] as $needle) {
    $assert(str_contains($assessment, $needle), 'Assessment contract missing: ' . $needle);
}

$factRevision = $read('app/Domains/Diagnostic/Model/FactRevision.php');
foreach (['TruthLevel $truthLevel','float $confidence','?int $supersedesRevision'] as $needle) {
    $assert(str_contains($factRevision, $needle), 'FactRevision contract missing: ' . $needle);
}

$state = $read('app/Domains/Diagnostic/Model/DiagnosticState.php');
$builder = $read('app/Domains/Diagnostic/Model/DiagnosticStateBuilder.php');
$assert(str_contains($state, 'evidenceGaps'), 'DiagnosticState must expose evidence gaps.');
$assert(str_contains($builder, "'reason' => 'missing_fact'"), 'DiagnosticStateBuilder must rebuild missing-fact gaps.');
$assert(str_contains($builder, "'reason' => 'contradictory_fact'"), 'DiagnosticStateBuilder must rebuild contradiction gaps.');

$compiler = $read('app/Domains/Diagnostic/Methodology/PackCompiler.php');
foreach (['PackLoader', 'PackValidator', 'CompiledDiagnosticPack'] as $needle) {
    $assert(str_contains($compiler, $needle), 'Compiler pipeline missing: ' . $needle);
}

$migration = 'app/migrations/20260928_000064_diagnostic_v070_semantic_convergence.sql';
$assert(in_array($migration, $manifest['contributions']['migration_files'] ?? [], true), 'Diagnostic V0.7 migration is not owned by the manifest.');
$sql = $read($migration);
foreach ([
    'diagnostic_fact_revisions',
    'diagnostic_assessment_revisions',
    'diagnostic_hypothesis_revisions',
    'diagnostic_recommendation_transitions',
    'diagnostic_state_snapshots',
    'supersedes_revision',
    'CONFIRMED_ROOT_CAUSE',
] as $needle) {
    $assert(str_contains($sql, $needle), 'Diagnostic V0.7 migration missing: ' . $needle);
}

echo "Diagnostic V0.7 normative convergence architecture: OK\n";
