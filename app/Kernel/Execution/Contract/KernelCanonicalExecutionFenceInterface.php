<?php
declare(strict_types=1);
namespace Kernel\Execution\Contract;

use Kernel\Agent\AgentInvocation;
use Kernel\Queue\Job;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Execution\KernelExecutionClaim;

interface KernelCanonicalExecutionFenceInterface
{
    public function beginAgent(Job $job,string $agentName):?KernelExecutionClaim;
    public function assertAgentAdmitted(AgentInvocation $invocation):void;
    public function completeAgent(KernelExecutionClaim $claim,string $nativeAgentRunId):void;
    public function beginTool(ToolInvocation $invocation,ToolDefinition $definition,string $auditId):?KernelExecutionClaim;
    public function completeTool(KernelExecutionClaim $claim,string $auditId):void;
    public function quarantine(KernelExecutionClaim $claim):void;
}
