<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Domain\Workflow\ReadyForHumanApprovalEvidence;
use App\Engineering\Domain\Workflow\ReadyForHumanApprovalGuard;

$ready = static fn (array $override = []): ReadyForHumanApprovalEvidence => new ReadyForHumanApprovalEvidence(
    architectureApproved: $override['architectureApproved'] ?? true,
    developmentCompleted: $override['developmentCompleted'] ?? true,
    reviewApproved: $override['reviewApproved'] ?? true,
    qaPassed: $override['qaPassed'] ?? true,
    ciPassed: $override['ciPassed'] ?? true,
    allBlockingAcceptanceCriteriaVerified: $override['allBlockingAcceptanceCriteriaVerified'] ?? true,
    hasOpenCriticalFinding: $override['hasOpenCriticalFinding'] ?? false,
    hasBlockingHumanDecision: $override['hasBlockingHumanDecision'] ?? false,
    hasRunningTask: $override['hasRunningTask'] ?? false,
    tenantIsolationVerified: $override['tenantIsolationVerified'] ?? true,
    authorizationVerified: $override['authorizationVerified'] ?? true,
    authenticationVerifiedOrNotApplicable: $override['authenticationVerifiedOrNotApplicable'] ?? true,
    migrationVerifiedOrNotApplicable: $override['migrationVerifiedOrNotApplicable'] ?? true,
    rollbackVerifiedOrNotApplicable: $override['rollbackVerifiedOrNotApplicable'] ?? true,
    apiCompatibilityVerifiedOrNotApplicable: $override['apiCompatibilityVerifiedOrNotApplicable'] ?? true,
    staticAnalysisPassed: $override['staticAnalysisPassed'] ?? true,
    requiredTestsPassed: $override['requiredTestsPassed'] ?? true,
    smokePassed: $override['smokePassed'] ?? true,
    documentationImpactChecked: $override['documentationImpactChecked'] ?? true,
    hasOpenMajorOrHigherFinding: $override['hasOpenMajorOrHigherFinding'] ?? false,
    revisionConsistent: $override['revisionConsistent'] ?? true,
);

$guard = new ReadyForHumanApprovalGuard();
$guard->assert($ready());

$failures = [
    'ciPassed' => 'ci_not_passed',
    'tenantIsolationVerified' => 'tenant_isolation_unverified',
    'authorizationVerified' => 'authorization_unverified',
    'authenticationVerifiedOrNotApplicable' => 'authentication_unverified',
    'migrationVerifiedOrNotApplicable' => 'migration_unverified',
    'rollbackVerifiedOrNotApplicable' => 'rollback_unverified',
    'apiCompatibilityVerifiedOrNotApplicable' => 'api_compatibility_unverified',
    'staticAnalysisPassed' => 'static_analysis_failed_or_missing',
    'requiredTestsPassed' => 'required_tests_failed_or_missing',
    'smokePassed' => 'smoke_failed_or_missing',
    'documentationImpactChecked' => 'documentation_impact_unchecked',
    'revisionConsistent' => 'revision_mismatch',
];

foreach ($failures as $field => $expected) {
    try {
        $guard->assert($ready([$field => false]));
        throw new RuntimeException('READY gate accepted failed '.$field.'.');
    } catch (LogicException $error) {
        if (!str_contains($error->getMessage(), $expected)) throw $error;
    }
}

foreach (['hasOpenCriticalFinding','hasOpenMajorOrHigherFinding','hasBlockingHumanDecision','hasRunningTask'] as $field) {
    try {
        $guard->assert($ready([$field => true]));
        throw new RuntimeException('READY gate accepted blocking '.$field.'.');
    } catch (LogicException) {
    }
}

echo "Engineering READY gate passed.\n";
