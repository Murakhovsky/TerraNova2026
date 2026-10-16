<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\EngineeringAgentTask;

/**
 * Must authorize the persisted, fenced AgentRun immediately before each
 * invocation of external Agent runtime. Never authorizes Engineering business
 * transitions, repository mutations, QA/review or human approvals.
 */
interface EngineeringTechnicalExecutionAdmissionInterface
{
    public function assertReady(EngineeringAgentTask $task, string $organizationId, string $runTraceId): void;
}
