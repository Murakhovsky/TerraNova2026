<?php
declare(strict_types=1);

namespace App\Engineering\Application\Workflow;

final readonly class WorkflowCounters
{
    public function __construct(
        public int $developmentFixLoops = 0,
        public int $reviewCycles = 0,
        public int $qaCycles = 0,
    ) {
    }
}
