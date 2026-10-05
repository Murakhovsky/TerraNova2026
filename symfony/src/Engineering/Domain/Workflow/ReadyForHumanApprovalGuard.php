<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

use LogicException;

final class ReadyForHumanApprovalGuard
{
    public function assert(ReadyForHumanApprovalEvidence $evidence): void
    {
        $failures = [];
        if (!$evidence->architectureApproved) $failures[] = 'architecture_not_approved';
        if (!$evidence->developmentCompleted) $failures[] = 'development_not_completed';
        if (!$evidence->reviewApproved) $failures[] = 'review_not_approved';
        if (!$evidence->qaPassed) $failures[] = 'qa_not_passed';
        if (!$evidence->ciPassed) $failures[] = 'ci_not_passed';
        if (!$evidence->allBlockingAcceptanceCriteriaVerified) $failures[] = 'acceptance_criteria_unverified';
        if (!$evidence->tenantIsolationVerified) $failures[] = 'tenant_isolation_unverified';
        if (!$evidence->authorizationVerified) $failures[] = 'authorization_unverified';
        if (!$evidence->authenticationVerifiedOrNotApplicable) $failures[] = 'authentication_unverified';
        if (!$evidence->migrationVerifiedOrNotApplicable) $failures[] = 'migration_unverified';
        if (!$evidence->rollbackVerifiedOrNotApplicable) $failures[] = 'rollback_unverified';
        if (!$evidence->apiCompatibilityVerifiedOrNotApplicable) $failures[] = 'api_compatibility_unverified';
        if (!$evidence->staticAnalysisPassed) $failures[] = 'static_analysis_failed_or_missing';
        if (!$evidence->requiredTestsPassed) $failures[] = 'required_tests_failed_or_missing';
        if (!$evidence->smokePassed) $failures[] = 'smoke_failed_or_missing';
        if (!$evidence->documentationImpactChecked) $failures[] = 'documentation_impact_unchecked';
        if ($evidence->hasOpenCriticalFinding) $failures[] = 'open_critical_finding';
        if ($evidence->hasOpenMajorOrHigherFinding) $failures[] = 'open_major_or_higher_finding';
        if ($evidence->hasBlockingHumanDecision) $failures[] = 'blocking_human_decision';
        if ($evidence->hasRunningTask) $failures[] = 'running_task';
        if (!$evidence->revisionConsistent) $failures[] = 'revision_mismatch';

        if ($failures !== []) {
            throw new LogicException('READY_FOR_HUMAN_APPROVAL denied: '.implode(', ', $failures));
        }
    }
}
