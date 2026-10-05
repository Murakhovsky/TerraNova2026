<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Workflow\WorkflowDirective;

final readonly class EngineeringQaPlannerStageExecutor
{
    public function __construct(private EngineeringQaStageExecutor $qa) {}

    public function execute(
        string $featureId,
        string $workflowId,
        string $organizationId,
        string $correlationId,
        int $logicalAttempt = 1,
    ): WorkflowDirective {
        return $this->qa->executePlanning(
            $featureId,
            $workflowId,
            $organizationId,
            $correlationId,
            $logicalAttempt,
        );
    }
}
