<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'symfony/src/Web/Experience/Governance/GoldenQaEvidenceRegistry.php',
    'symfony/src/Command/ExperienceQaIngestCommand.php',
    'symfony/src/Web/Experience/Governance/PageContractOutcomeAuditService.php',
    'symfony/src/Command/ExperienceContractOutcomeAuditCommand.php',
];
foreach ($files as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('UX evidence closure file missing: '.$file);
    }
}

$evidence = (string) file_get_contents($root.'/'.$files[0]);
foreach ([
    'source_commit_sha',
    'tested_checkout_sha',
    'workflow_run_id',
    'workflow_run_attempt',
    'observed_at',
    'accessibility_violations',
    'github-actions',
    'count(self::PAGES) * count(self::VIEWPORTS)',
    'human_acceptance',
    'tempnam',
    'rename',
] as $marker) {
    if (!str_contains($evidence, $marker)) {
        throw new RuntimeException('Golden QA provenance/atomicity gate missing: '.$marker);
    }
}

$audit = (string) file_get_contents($root.'/'.$files[2]);
foreach ([
    'PageExperienceStatus::Superseded',
    'PageExperienceStatus::Deprecated',
    'PageExperienceStatus::Exempt',
    'successCondition',
    'primaryAction',
    'missing_states',
    'implemented_actions_verified',
] as $marker) {
    if (!str_contains($audit, $marker)) {
        throw new RuntimeException('Page Contract outcome audit missing: '.$marker);
    }
}

$runtime = (string) file_get_contents($root.'/.github/workflows/runtime.yml');
foreach (['Reconcile Golden Eight QA', 'cos:experience:qa:ingest', 'page-contract-qa.json'] as $marker) {
    if (!str_contains($runtime, $marker)) {
        throw new RuntimeException('Golden QA CI reconciliation missing: '.$marker);
    }
}
$dashboard = (string) file_get_contents($root.'/symfony/src/Web/Experience/Dev/ExperienceDashboardController.php');
foreach (['qaEvidence', 'contractOutcomes'] as $marker) {
    if (!str_contains($dashboard, $marker)) {
        throw new RuntimeException('Experience dashboard is missing '.$marker);
    }
}
$decisions = (string) file_get_contents($root.'/resources/experience/golden-decisions.yaml');
if (!str_contains($decisions, 'PENDING')) {
    throw new RuntimeException('Golden human decisions must not be auto-accepted.');
}
echo "Experience UXD-001/003 evidence and acceptance boundary contract OK\n";
