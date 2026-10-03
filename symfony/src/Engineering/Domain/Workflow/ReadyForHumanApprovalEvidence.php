<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

final readonly class ReadyForHumanApprovalEvidence
{
    public function __construct(
        public bool $architectureApproved,
        public bool $developmentCompleted,
        public bool $reviewApproved,
        public bool $qaPassed,
        public bool $ciPassed,
        public bool $allBlockingAcceptanceCriteriaVerified,
        public bool $hasOpenCriticalFinding,
        public bool $hasBlockingHumanDecision,
        public bool $hasRunningTask,
    ) {
    }
}
