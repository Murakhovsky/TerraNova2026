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
        if ($evidence->hasOpenCriticalFinding) $failures[] = 'open_critical_finding';
        if ($evidence->hasBlockingHumanDecision) $failures[] = 'blocking_human_decision';
        if ($evidence->hasRunningTask) $failures[] = 'running_task';

        if ($failures !== []) {
            throw new LogicException('READY_FOR_HUMAN_APPROVAL denied: '.implode(', ', $failures));
        }
    }
}
