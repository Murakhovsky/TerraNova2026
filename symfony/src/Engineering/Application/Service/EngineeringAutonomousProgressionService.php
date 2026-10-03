<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Workflow\WorkflowDirective;
use App\Engineering\Domain\Agent\AgentRole;

final readonly class EngineeringAutonomousProgressionService
{
    public function __construct(
        private EngineeringArchitectStageExecutor $architect,
        private EngineeringDeveloperStageExecutor $developer,
        private EngineeringReviewerStageExecutor $reviewer,
        private EngineeringQaStageExecutor $qa,
        private EngineeringAgentRunStoreInterface $agentRuns,
    ) {}

    public function continue(
        string $featureId,
        string $workflowId,
        WorkflowDirective $directive,
        string $organizationId,
        string $correlationId,
    ): WorkflowDirective {
        for ($step = 0; $step < 12; ++$step) {
            $role = $directive->agent;
            if ($role === null) return $directive;

            $directive = match ($role) {
                AgentRole::PRINCIPAL_ARCHITECT => $this->architect->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, AgentRole::PRINCIPAL_ARCHITECT),
                ),
                AgentRole::DEVELOPER => $this->developer->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, AgentRole::DEVELOPER),
                ),
                AgentRole::REVIEWER => $this->reviewer->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, AgentRole::REVIEWER),
                ),
                AgentRole::QA => $this->qa->execute(
                    featureId: $featureId,
                    workflowId: $workflowId,
                    organizationId: $organizationId,
                    correlationId: $correlationId,
                    logicalAttempt: $this->nextAttempt($featureId, AgentRole::QA),
                ),
                default => $directive,
            };

            if ($directive->agent === $role) {
                return $directive;
            }
        }

        throw new \RuntimeException('Engineering autonomous progression exceeded the V0.1 safety step limit.');
    }

    private function nextAttempt(string $featureId, AgentRole $role): int
    {
        $count = 0;
        foreach ($this->agentRuns->forFeature($featureId) as $run) {
            if (($run['role'] ?? null) === $role->value) ++$count;
        }
        return $count + 1;
    }
}

