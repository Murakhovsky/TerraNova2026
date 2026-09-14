<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\AgentResult;

interface DecisionRepositoryInterface
{
    public function save(string $runId, AgentDefinition $agent, AgentInvocation $invocation, AgentResult $result): string;
}
