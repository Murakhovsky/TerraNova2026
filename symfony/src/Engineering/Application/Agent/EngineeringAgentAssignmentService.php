<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Domain\Agent\AgentRole;
use RuntimeException;

final readonly class EngineeringAgentAssignmentService
{
    public function __construct(
        private AgentCapabilityRegistry $capabilities,
        private EngineeringAgentRunStoreInterface $runs,
    ) {}

    public function assertAssignable(string $featureId, AgentRole $role, string $mode = 'FEATURE', string $riskLevel = 'MEDIUM'): void
    {
        if ($role === AgentRole::QA) {
            throw new RuntimeException('Legacy QA role cannot be assigned to new Engineering Runtime V2 executions.');
        }
        $this->capabilities->assertAssignable($role, $mode, $riskLevel);

        $history = $this->runs->forFeature($featureId);
        if ($role === AgentRole::REVIEWER && !$this->hasCompletedRole($history, AgentRole::DEVELOPER)) {
            throw new RuntimeException('Reviewer cannot run before an independent Developer execution exists.');
        }
        if ($role === AgentRole::QA_EXECUTOR) {
            if (!$this->hasCompletedRole($history, AgentRole::DEVELOPER) || !$this->hasCompletedRole($history, AgentRole::REVIEWER)) {
                throw new RuntimeException('QA Executor requires independent Developer and Reviewer executions.');
            }
        }
    }

    /** @param list<array<string,mixed>> $history */
    private function hasCompletedRole(array $history, AgentRole $role): bool
    {
        foreach ($history as $run) {
            if (($run['role'] ?? null) === $role->value && strtoupper((string) ($run['status'] ?? '')) === 'COMPLETED') return true;
        }
        return false;
    }
}
