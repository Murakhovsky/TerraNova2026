<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;

final class EngineeringSpecialistRequirementResolver
{
    /** @return list<AgentRole> */
    public function resolve(array $featureSpec, array $architecture = [], array $changedPaths = []): array
    {
        $haystack = strtolower(json_encode([$featureSpec, $architecture, $changedPaths], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $roles = [];

        $this->addWhen($roles, AgentRole::SECURITY_SPECIALIST, $haystack, [
            'auth','permission','secret','credential','crypt','webhook','tenant','sensitive','security',
        ]);
        $this->addWhen($roles, AgentRole::DATABASE_MIGRATION_SPECIALIST, $haystack, [
            'migration','schema','database','table','column','index','foreign key',
        ]);
        $this->addWhen($roles, AgentRole::PERFORMANCE_SPECIALIST, $haystack, [
            'performance','latency','concurrency','batch','large dataset','n+1','memory',
        ]);
        $this->addWhen($roles, AgentRole::DEVOPS_SPECIALIST, $haystack, [
            'docker','deploy','ci/cd','worker','queue','cron','nginx','environment','health check',
        ]);
        $this->addWhen($roles, AgentRole::DOCUMENTATION_SPECIALIST, $haystack, [
            'documentation','docs/','readme','public documentation','integrator documentation',
        ]);
        $this->addWhen($roles, AgentRole::API_SPECIALIST, $haystack, [
            'api','openapi','endpoint','webhook','public dto','versioned contract',
        ]);

        return array_values(array_unique($roles, SORT_REGULAR));
    }

    /** @param list<AgentRole> $roles @param list<string> $needles */
    private function addWhen(array &$roles, AgentRole $role, string $haystack, array $needles): void
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                $roles[] = $role;
                return;
            }
        }
    }
}
