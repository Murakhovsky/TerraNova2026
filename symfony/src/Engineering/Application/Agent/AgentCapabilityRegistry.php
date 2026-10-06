<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;
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
            $data = $this->parseDefinition($file);
            if (!is_array($data)) continue;
            $role = strtoupper(trim((string) ($data['role'] ?? '')));
            if ($role === '') continue;
            $this->definitions[$role] = $data;
        }
    }

    /** @return array<string,mixed> */
    private function parseDefinition(string $file): array
    {
        if (class_exists('Symfony\\Component\\Yaml\\Yaml')) {
            $data = \Symfony\Component\Yaml\Yaml::parseFile($file);
            return is_array($data) ? $data : [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Cannot read Engineering agent capability definition: '.$file);
        }

        $result = [];
        $section = null;
        $subsection = null;
        $listKey = null;

        foreach ($lines as $raw) {
            if (trim($raw) === '' || str_starts_with(ltrim($raw), '#')) continue;

            $indent = strlen($raw) - strlen(ltrim($raw, ' '));
            $line = trim($raw);

            if ($indent === 0 && preg_match('/^([A-Za-z0-9_]+):(?:\\s*(.*))?$/', $line, $match) === 1) {
                $section = $match[1];
                $subsection = null;
                $listKey = null;
                $value = trim((string) ($match[2] ?? ''));
                if ($value !== '') $result[$section] = $this->scalar($value);
                elseif (!isset($result[$section])) $result[$section] = [];
                continue;
            }

            if ($section === 'permissions') {
                if ($indent === 2 && preg_match('/^([A-Za-z0-9_]+):(?:\\s*(.*))?$/', $line, $match) === 1) {
                    $subsection = $match[1];
                    $listKey = null;
                    $value = trim((string) ($match[2] ?? ''));
                    $result['permissions'][$subsection] = $value !== '' ? $this->scalar($value) : [];
                    continue;
                }
                if ($indent === 4 && $subsection !== null && preg_match('/^([A-Za-z0-9_]+):(?:\\s*(.*))?$/', $line, $match) === 1) {
                    $key = $match[1];
                    $value = trim((string) ($match[2] ?? ''));
                    if ($value === '') {
                        $result['permissions'][$subsection][$key] = [];
                        $listKey = $key;
                    } else {
                        $result['permissions'][$subsection][$key] = $this->scalar($value);
                        $listKey = null;
                    }
                    continue;
                }
                if ($indent >= 6 && $subsection !== null && $listKey !== null && str_starts_with($line, '- ')) {
                    $result['permissions'][$subsection][$listKey][] = $this->scalar(trim(substr($line, 2)));
                    continue;
                }
            }

            if (in_array($section, ['supported_modes','risk_levels','tools'], true) && str_starts_with($line, '- ')) {
                $result[$section][] = $this->scalar(trim(substr($line, 2)));
            }
        }

        return $result;
    }

    private function scalar(string $value): mixed
    {
        $value = trim($value, " \t\n\r\0\x0B\"'");
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null', '~' => null,
            default => $value,
        };
    }
}
