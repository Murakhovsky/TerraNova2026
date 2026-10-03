<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Workflow;

final readonly class EngineeringRetryPolicy
{
    public function __construct(
        private int $maxTechnicalRetries = 2,
        private int $maxDevelopmentFixLoops = 3,
        private int $maxReviewCycles = 3,
        private int $maxQaCycles = 3,
    ) {
    }

    public function mayRetryTechnical(int $technicalRetry): bool { return $technicalRetry < $this->maxTechnicalRetries; }
    public function mayRunDevelopmentFix(int $completedFixLoops): bool { return $completedFixLoops < $this->maxDevelopmentFixLoops; }
    public function mayRunReview(int $completedReviewCycles): bool { return $completedReviewCycles < $this->maxReviewCycles; }
    public function mayRunQa(int $completedQaCycles): bool { return $completedQaCycles < $this->maxQaCycles; }
}
