<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$specialist = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringSpecialistStageExecutor.php');
$architect = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$recovery = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringArchitectSecurityRecoveryContext.php');

foreach ([
    "'reviewed_architecture_artifact_id'",
    "'reviewed_architecture_content_hash'",
    "'phase' => \$phase",
] as $marker) {
    if (!str_contains($specialist, $marker)) throw new RuntimeException('Security review lost architecture provenance: '.$marker);
}
foreach ([
    'security_review_recovery',
    'architect.security_findings_recovery_loaded',
    'SECURITY_REVIEW_REPORT',
    'source_artifact_hash',
    'independent Security Specialist re-review',
] as $marker) {
    if (!str_contains($architect, $marker)) throw new RuntimeException('Targeted Architect recovery missing: '.$marker);
}
if (!str_contains($recovery, "'resolution_state' => 'UNVERIFIED'")
    || !str_contains($recovery, "'development_authorized' => false")
    || !str_contains($recovery, "'independent_security_recheck_required' => true")) {
    throw new RuntimeException('Security review recovery accidentally self-approves findings.');
}

echo "Engineering specialist findings targeted recovery contract passed.\n";
