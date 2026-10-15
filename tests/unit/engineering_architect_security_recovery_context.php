<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/symfony/src/Engineering/Application/Service/EngineeringArchitectSecurityRecoveryContext.php';

use App\Engineering\Application\Service\EngineeringArchitectSecurityRecoveryContext;

$builder = new EngineeringArchitectSecurityRecoveryContext();
$review = [
    'id' => 'review-1', 'content_hash' => 'hash-1',
    'content' => [
        'status' => 'REQUEST_CHANGES',
        'evidence' => ['phase' => 'PRE_DEVELOPMENT'],
        'reviewed_revision' => 'revision-a',
        'findings' => [
            ['id' => 'SEC-001', 'severity' => 'MAJOR', 'title' => 'Cache isolation', 'risk' => 'Cross-session HTML',
                'required_resolution' => 'Prove private, no-store, service-worker and HEAD behavior.',
                'evidence' => ['Old architecture says public max-age=60.']],
            ['id' => 'SEC-002', 'severity' => 'MEDIUM', 'title' => 'Identity',
                'required_resolution' => 'Verify loader and tenant boundaries.'],
        ],
        'required_actions' => [
            ['id' => 'ACT-001', 'finding_id' => 'SEC-001', 'action' => 'Inspect the root and PWA code.', 'timing' => 'Before implementation'],
            ['id' => 'ACT-002', 'finding_id' => 'SEC-002', 'action' => 'Test identity contract.', 'timing' => 'Before completion'],
            ['id' => 'ACT-BOGUS', 'finding_id' => 'SEC-999', 'action' => 'Unmatched action'],
        ],
    ],
];
$context = $builder->fromArtifact($review, 'revision-a');
if ($context === null || ($context['source_artifact_id'] ?? '') !== 'review-1'
    || $context['revision_binding'] !== 'MATCH'
    || $context['architecture_binding'] !== 'UNVERIFIED_LEGACY'
    || $context['finding_ids'] !== ['SEC-001', 'SEC-002']
    || count($context['required_actions']) !== 2
    || $context['findings'][0]['resolution_state'] !== 'UNVERIFIED'
    || $context['development_authorized'] !== false
    || $context['independent_security_recheck_required'] !== true) {
    throw new RuntimeException('Bounded specialist recovery context lost review provenance or mandatory findings.');
}
$stale = $builder->fromArtifact($review, 'revision-b');
if (($stale['revision_binding'] ?? null) !== 'UNVERIFIED_OR_STALE') {
    throw new RuntimeException('Stale specialist review was incorrectly bound to current repository.');
}
$explicit = $review;
$explicit['content']['reviewed_architecture_content_hash'] = 'architecture-v2-hash';
if (($builder->fromArtifact($explicit, 'revision-a')['reviewed_architecture_content_hash'] ?? null) !== 'architecture-v2-hash') {
    throw new RuntimeException('Independent review lost architecture hash provenance.');
}
$approved = $review;
$approved['content']['status'] = 'APPROVED';
if ($builder->fromArtifact($approved, 'revision-a') !== null || $builder->fromArtifact(null, 'revision-a') !== null) {
    throw new RuntimeException('Approved or missing specialist review must not trigger rework.');
}
echo "Engineering Architect targeted Security recovery context passed.\n";
