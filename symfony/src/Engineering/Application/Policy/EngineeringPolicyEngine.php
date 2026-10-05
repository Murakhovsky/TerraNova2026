<?php
declare(strict_types=1);

namespace App\Engineering\Application\Policy;

use App\Engineering\Domain\Agent\AgentRole;

final readonly class EngineeringPolicyEngine
{
    public function __construct(private AgentCapabilityRegistry $agents = new AgentCapabilityRegistry()) {}

    /** @param array<string,mixed> $feature @return array{allowed:bool,human_approval_required:bool,reasons:list<string>} */
    public function featureStart(array $feature): array
    {
        $risk = strtoupper(trim((string) ($feature['risk'] ?? 'MEDIUM')));
        $reasons = [];
        $human = in_array($risk, ['HIGH','CRITICAL'], true);

        if (!in_array($risk, ['LOW','MEDIUM','HIGH','CRITICAL'], true)) {
            $reasons[] = 'Unknown feature risk classification.';
        }
        if (($feature['forbidden_paths'] ?? null) !== null && !is_array($feature['forbidden_paths'])) {
            $reasons[] = 'Feature forbidden path policy is invalid.';
        }

        return ['allowed' => $reasons === [], 'human_approval_required' => $human, 'reasons' => $reasons];
    }

    /** @param array<string,mixed> $feature */
    public function sharedKernelModificationRequiresHuman(array $feature): bool
    {
        foreach (['owned_paths','shared_paths'] as $field) {
            foreach (is_array($feature[$field] ?? null) ? $feature[$field] : [] as $path) {
                $normalized = ltrim(str_replace('\\', '/', trim((string) $path)), '/');
                if (str_starts_with($normalized, 'app/Kernel/Shared/')) return true;
            }
        }
        return false;
    }

    public function canModifyPath(AgentRole $role): bool
    {
        return $this->agents->allows($role, 'repository_write');
    }

    public function canMerge(AgentRole $role): bool
    {
        return $this->agents->allows($role, 'merge');
    }

    public function contractChangeRequiresHuman(string $compatibility): bool
    {
        return strtoupper(trim($compatibility)) === 'BREAKING';
    }

    /** @param array<string,mixed> $migrationPlan */
    public function migrationRequiresHuman(array $migrationPlan): bool
    {
        $risk = strtoupper(trim((string) ($migrationPlan['risk'] ?? '')));
        return in_array($risk, ['HIGH','CRITICAL'], true)
            || (($migrationPlan['requires_downtime'] ?? false) === true)
            || (($migrationPlan['destructive'] ?? false) === true);
    }

    public function externalProductionIntegrationRequiresHuman(): bool
    {
        return true;
    }

    public function domainReleaseRequiresHuman(): bool
    {
        return true;
    }
}
