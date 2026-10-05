<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;
use Symfony\Component\Yaml\Yaml;
use RuntimeException;

final class AgentCapabilityRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $definitions = [];

    public function __construct(private readonly string $configDirectory)
    {
    }

    /** @return array<string,mixed> */
    public function forRole(AgentRole $role): array
    {
        $this->load();
        $definition = $this->definitions[$role->value] ?? null;
        if (!is_array($definition)) {
            throw new RuntimeException('Engineering agent capability definition is missing for '.$role->value.'.');
        }
        return $definition;
    }

    public function supports(AgentRole $role, string $mode = 'FEATURE'): bool
    {
        try {
            $definition = $this->forRole($role);
        } catch (RuntimeException) {
            return false;
        }
        if (($definition['enabled'] ?? true) !== true) return false;
        $modes = $definition['supported_modes'] ?? ['FEATURE','DOMAIN'];
        return !is_array($modes) || $modes === [] || in_array($mode, $modes, true);
    }

    public function assertAssignable(AgentRole $role, string $mode, string $riskLevel = 'MEDIUM'): void
    {
        $definition = $this->forRole($role);
        if (($definition['enabled'] ?? true) !== true) {
            throw new RuntimeException('Engineering agent role is disabled: '.$role->value);
        }
        $modes = $definition['supported_modes'] ?? ['FEATURE','DOMAIN'];
        if (is_array($modes) && $modes !== [] && !in_array($mode, $modes, true)) {
            throw new RuntimeException($role->value.' does not support execution mode '.$mode.'.');
        }
        $levels = $definition['risk_levels'] ?? ['LOW','MEDIUM','HIGH','CRITICAL'];
        if (is_array($levels) && $levels !== [] && !in_array(strtoupper($riskLevel), array_map('strtoupper', $levels), true)) {
            throw new RuntimeException($role->value.' is not permitted for risk '.$riskLevel.'.');
        }
    }

    /** @return list<AgentRole> */
    public function activeRoles(): array
    {
        $this->load();
        $roles = [];
        foreach (array_keys($this->definitions) as $role) {
            $enum = AgentRole::tryFrom($role);
            if ($enum !== null && $enum !== AgentRole::QA && (($this->definitions[$role]['enabled'] ?? true) === true)) $roles[] = $enum;
        }
        return $roles;
    }

    private function load(): void
    {
        if ($this->definitions !== []) return;
        foreach (glob(rtrim($this->configDirectory, '/')."/*.yaml") ?: [] as $file) {
            $data = Yaml::parseFile($file);
            if (!is_array($data)) continue;
            $role = strtoupper(trim((string) ($data['role'] ?? '')));
            if ($role === '') continue;
            $this->definitions[$role] = $data;
        }
    }
}
