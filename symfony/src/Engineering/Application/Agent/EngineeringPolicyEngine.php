<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;
use RuntimeException;

final readonly class EngineeringPolicyEngine
{
    public function __construct(private AgentCapabilityRegistry $capabilities) {}

    /** @param list<array<string,mixed>> $history */
    public function assertExecutionAllowed(
        AgentRole $role,
        string $mode,
        string $riskLevel,
        array $history = [],
    ): void {
        if ($role === AgentRole::ENGINEERING_MANAGER) {
            throw new RuntimeException('Engineering Manager is orchestration-only in Runtime V2.');
        }
        if ($role === AgentRole::QA) {
            throw new RuntimeException('Legacy QA role is forbidden for new Engineering Runtime V2 executions.');
        }

        $this->capabilities->assertAssignable($role, $mode, $riskLevel);

        if ($role === AgentRole::REVIEWER && !$this->hasCompleted($history, AgentRole::DEVELOPER)) {
            throw new RuntimeException('Reviewer requires a completed independent Developer execution.');
        }
        if ($role === AgentRole::QA_EXECUTOR) {
            if (!$this->hasCompleted($history, AgentRole::DEVELOPER) || !$this->hasCompleted($history, AgentRole::REVIEWER)) {
                throw new RuntimeException('QA Executor requires completed Developer and Reviewer executions.');
            }
        }
    }

    /** @param list<array<string,mixed>> $history */
    private function hasCompleted(array $history, AgentRole $role): bool
    {
        foreach ($history as $run) {
            if (($run['role'] ?? null) === $role->value && strtoupper((string) ($run['status'] ?? '')) === 'COMPLETED') {
                return true;
            }
        }
        return false;
    }
}
