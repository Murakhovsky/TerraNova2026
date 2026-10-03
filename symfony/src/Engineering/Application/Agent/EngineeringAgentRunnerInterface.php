<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\EngineeringAgentTask;

interface EngineeringAgentRunnerInterface
{
    public function run(EngineeringAgentTask $task, string $organizationId, string $correlationId): EngineeringAgentRunResult;
}
