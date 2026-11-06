<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

final readonly class EngineeringAutopilotAdmissionPolicy
{
    /**
     * GitHub issue bodies are untrusted task input, never instructions with authority
     * to override this policy, Engineering standards or repository constitution.
     *
     * @param array<string,mixed> $issue
     * @param callable(int):bool $dependencyClosed
     * @return array{admitted:bool,reason:string,priority:string,paths:list<string>,dependencies:list<int>}
     */
    public function evaluate(array $issue, callable $dependencyClosed): array
    {
        $labels = array_map('strtolower', array_map('strval', $issue['labels'] ?? []));
        $priority = 'P2';
        foreach (['P0', 'P1', 'P2', 'P3'] as $p) {
            if (in_array(strtolower($p), $labels, true)) { $priority = $p; break; }
        }
        $deny = static fn (string $why): array => [
            'admitted' => false, 'reason' => $why, 'priority' => $priority,
            'paths' => [], 'dependencies' => [],
        ];

        if (($issue['state'] ?? '') !== 'open' || ($issue['is_pull_request'] ?? true) !== false) {
            return $deny('Only open GitHub Issues are eligible.');
        }
        if (!in_array('cos:autopilot:ready', $labels, true) || !in_array('cos:autopilot:low-risk', $labels, true)) {
            return $deny('Both explicit ready and low-risk labels are required.');
        }
        foreach (['cos:autopilot:paused', 'cos:autopilot:claimed', 'security', 'risk:high',
            'risk:critical', 'breaking-change', 'production', 'database-migration'] as $blockedLabel) {
            if (in_array($blockedLabel, $labels, true)) return $deny('Restricted label: '.$blockedLabel);
        }
        if ($priority === 'P0') return $deny('P0 changes require direct human supervision.');

        $body = (string) ($issue['body'] ?? '');
        if (strlen($body) > 30000) return $deny('Issue description exceeds bounded context.');
        $acceptance = $this->section($body, 'Acceptance Criteria');
        if ($acceptance === null || !preg_match('/^-\s*\[ \]\s+\S/im', $acceptance)) {
            return $deny('A checklist under ## Acceptance Criteria is mandatory.');
        }
        $pathSection = $this->section($body, 'Allowed Paths');
        if ($pathSection === null) return $deny('An explicit ## Allowed Paths section is mandatory.');
        preg_match_all('/^-\s*\x60([^\x60]+)\x60\s*$/m', $pathSection, $matches);
        $paths = array_values(array_unique(array_map('trim', $matches[1] ?? [])));
        if ($paths === [] || count($paths) > 12) return $deny('Provide 1..12 bounded allowed paths.');

        foreach ($paths as $path) {
            if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, '*')
                || str_starts_with($path, '/') || !preg_match('#^(app/Domains/|app/Platform/|symfony/src/|symfony/tests/|tests/|docs/09-development/|resources/)[A-Za-z0-9_./-]+$#', $path)) {
                return $deny('Path is outside the low-risk development allowlist: '.$path);
            }
            if (preg_match('#(^|/)(Security|Auth|Migration|Migrations|secrets?)(/|\.|$)#i', $path)) {
                return $deny('Sensitive code requires human approval: '.$path);
            }
        }

        $dependenciesSection = $this->section($body, 'Dependencies');
        if ($dependenciesSection === null) return $deny('Use ## Dependencies with None or issue references.');
        $dependencies = [];
        $deps = trim($dependenciesSection);
        if (!in_array(strtolower($deps), ['none', '- none'], true)) {
            $lines = preg_split('/\R/', $deps);
            foreach ($lines ?: [] as $line) {
                if (trim($line) === '') continue;
                if (!preg_match('/^-\s*#([1-9][0-9]{0,7})\s*$/', trim($line), $m)) {
                    return $deny('Dependencies must use one "- #123" per line or None.');
                }
                $dependencies[] = (int) $m[1];
            }
            if ($dependencies === [] || count($dependencies) > 12) return $deny('Invalid dependency list.');
        }
        $dependencies = array_values(array_unique($dependencies));
        foreach ($dependencies as $dependency) {
            if ($dependency === (int) ($issue['number'] ?? 0) || !$dependencyClosed($dependency)) {
                return $deny('Dependency #'.$dependency.' is not closed.');
            }
        }
        return ['admitted' => true, 'reason' => 'Approved bounded issue.', 'priority' => $priority,
            'paths' => $paths, 'dependencies' => $dependencies];
    }

    private function section(string $body, string $heading): ?string
    {
        if (!preg_match('/^##\s+'.preg_quote($heading, '/').'\s*\R([\s\S]*?)(?=^##\s+|\z)/mi', $body, $m)) {
            return null;
        }
        return trim($m[1]);
    }
}
