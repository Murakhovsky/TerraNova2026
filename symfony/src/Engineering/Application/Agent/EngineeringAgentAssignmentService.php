<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Domain\Agent\AgentRole;

final readonly class EngineeringAgentAssignmentService
{
    public function __construct(
        private EngineeringPolicyEngine $policy,
        private EngineeringAgentRunStoreInterface $runs,
    ) {}

    public function assertAssignable(string $featureId, AgentRole $role, string $mode = 'FEATURE', string $riskLevel = 'MEDIUM'): void
    {
        $history = $this->runs->forFeature($featureId);
        $this->policy->assertExecutionAllowed($role, $mode, $riskLevel, $history);
    }
}
