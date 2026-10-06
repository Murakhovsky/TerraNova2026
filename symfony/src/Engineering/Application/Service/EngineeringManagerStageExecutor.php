<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Workflow\WorkflowDirective;

/**
 * @deprecated Engineering Runtime V2 keeps Manager as orchestration-only.
 *             Feature requirements are executed by EngineeringProductRequirementsStageExecutor.
 */
final readonly class EngineeringManagerStageExecutor
{
    public function __construct(private EngineeringProductRequirementsStageExecutor $product) {}

    public function execute(
        string $featureId,
        string $workflowId,
        EngineeringRequest $request,
        string $organizationId,
        string $correlationId,
        int $logicalAttempt,
    ): WorkflowDirective {
        return $this->product->execute(
            featureId: $featureId,
            workflowId: $workflowId,
            request: $request,
            organizationId: $organizationId,
            correlationId: $correlationId,
            logicalAttempt: $logicalAttempt,
        );
    }
}
