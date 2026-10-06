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
            $developer = $this->latestCompleted($history, AgentRole::DEVELOPER);
            $reviewer = $this->latestCompleted($history, AgentRole::REVIEWER);
            if ($developer === null || $reviewer === null) {
                throw new RuntimeException('Independent approval identities are unavailable.');
            }
            $developerActor = trim((string) ($developer['actor_id'] ?? $developer['agent_id'] ?? ''));
            $reviewerActor = trim((string) ($reviewer['actor_id'] ?? $reviewer['agent_id'] ?? ''));
            if ($developerActor === '' || $reviewerActor === '' || hash_equals($developerActor, $reviewerActor)) {
                throw new RuntimeException('No Self Approval violation: Developer and Reviewer must have distinct execution actors.');
            }
        }
    }

    /** @param list<array<string,mixed>> $history */
    private function hasCompleted(array $history, AgentRole $role): bool
    {
        return $this->latestCompleted($history, $role) !== null;
    }

    /** @param list<array<string,mixed>> $history @return array<string,mixed>|null */
    private function latestCompleted(array $history, AgentRole $role): ?array
    {
        $match = null;
        foreach ($history as $run) {
            if (($run['role'] ?? null) === $role->value && strtoupper((string) ($run['status'] ?? '')) === 'COMPLETED') {
                $match = $run;
            }
        }
        return $match;
    }
}
