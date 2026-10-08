<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;

final class EngineeringSpecialistRequirementResolver
{
    /** @return list<AgentRole> */
    public function resolve(array $featureSpec, array $architecture = [], array $changedPaths = []): array
    {
        // Use evidence values only. Structural schema keys such as "security",
        // "database_changes" or "api_changes" are always present and must never
        // trigger a specialist by themselves.
        $haystack = $this->evidenceHaystack([$featureSpec, $architecture, $changedPaths]);
        $roles = [];

        $this->addWhen($roles, AgentRole::SECURITY_SPECIALIST, $haystack, [
            'auth','authentication','authorization','permission','permissions','secret','secrets',
            'credential','credentials','encrypt','encryption','cryptography','webhook','tenant','sensitive','security',
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
            if ($this->containsSignal($haystack, $needle)) {
                $roles[] = $role;
                return;
            }
        }
    }

    private function containsSignal(string $haystack, string $needle): bool
    {
        $pattern = '~(?<![a-z0-9_])'.preg_quote(strtolower($needle), '~').'(?![a-z0-9_])~u';
        return preg_match($pattern, $haystack) === 1;
    }

    private function evidenceHaystack(mixed $value): string
    {
        $parts = [];
        $this->collectEvidence($value, $parts);
        return strtolower(implode("\n", $parts));
    }

    /** @param list<string> $parts */
    private function collectEvidence(mixed $value, array &$parts): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) $this->collectEvidence($nested, $parts);
            return;
        }
        if (!is_scalar($value)) return;

        $text = trim((string) $value);
        if ($text === '' || $this->isExplicitlyNoImpact($text)) return;
        $parts[] = $text;
    }

    private function isExplicitlyNoImpact(string $text): bool
    {
        $normalized = strtolower(trim($text));
        if (preg_match('~^(?:not applicable|n/a|none)\b~u', $normalized) === 1) return true;
        if (preg_match('~^no\b.*\b(?:change|changes|impact|introduced|introduces|introduction|required|needed)\b[.!]?$~u', $normalized) === 1) return true;
        if (preg_match('~\b(?:remains?|is|are) unchanged\b~u', $normalized) === 1) return true;
        if (preg_match('~^does not\s+(?:change|affect|introduce|require|touch)\b~u', $normalized) === 1) return true;
        return false;
    }
}
