<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Workflow\WorkflowDirective;
use App\Engineering\Domain\Agent\AgentRole;

final readonly class EngineeringAutonomousProgressionService
{
    public function __construct(
        private EngineeringArchitectStageExecutor $architect,
        private EngineeringDeveloperStageExecutor $developer,
    ) {}

    public function continue(
        string $featureId,
        string $workflowId,
        WorkflowDirective $directive,
        string $organizationId,
        string $correlationId,
    ): WorkflowDirective {
        if ($directive->agent === AgentRole::PRINCIPAL_ARCHITECT) {
            $directive = $this->architect->execute(
                featureId: $featureId,
                workflowId: $workflowId,
                organizationId: $organizationId,
                correlationId: $correlationId,
            );
        }

        if ($directive->agent === AgentRole::DEVELOPER) {
            $directive = $this->developer->execute(
                featureId: $featureId,
                workflowId: $workflowId,
                organizationId: $organizationId,
                correlationId: $correlationId,
            );
        }

        return $directive;
    }
}
