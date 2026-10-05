<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;
use RuntimeException;

final readonly class EngineeringAgentToolPermissionPolicy
{
    public function __construct(private AgentCapabilityRegistry $capabilities) {}

    /** @param list<array<string,mixed>> $changes */
    public function assertRepositoryMutationAllowed(AgentRole $role, array $changes): void
    {
        $definition = $this->capabilities->forRole($role);
        $permissions = is_array($definition['permissions'] ?? null) ? $definition['permissions'] : [];
        $repository = is_array($permissions['repository'] ?? null) ? $permissions['repository'] : [];
        $write = $repository['write'] ?? false;

        if ($role === AgentRole::QA_EXECUTOR) {
            $testCode = is_array($permissions['test_code'] ?? null) ? $permissions['test_code'] : [];
            if (($testCode['write'] ?? false) !== true) {
                throw new RuntimeException('QA Executor is not permitted to mutate test code.');
            }
            $roots = is_array($testCode['roots'] ?? null) ? $testCode['roots'] : [];
            $this->assertPathsInsideRoots($changes, $roots, 'QA Executor');
            return;
        }

        if ($write === true || $write === 'branch_only') return;

        if ($write === 'docs_only') {
            $this->assertPathsInsideRoots($changes, ['docs/'], $role->value);
            return;
        }

        throw new RuntimeException($role->value.' is not permitted to mutate repository content.');
    }

    public function assertMergeDenied(AgentRole $role): void
    {
        $permissions = $this->capabilities->forRole($role)['permissions'] ?? [];
        if (($permissions['merge'] ?? false) === true) return;
        throw new RuntimeException($role->value.' is not permitted to merge.');
    }

    /** @param list<array<string,mixed>> $changes @param list<string> $roots */
    private function assertPathsInsideRoots(array $changes, array $roots, string $label): void
    {
        foreach ($changes as $change) {
            if (!is_array($change)) throw new RuntimeException($label.' mutation must be an object.');
            $path = str_replace('\\', '/', trim((string) ($change['path'] ?? '')));
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
                throw new RuntimeException($label.' mutation path is unsafe.');
            }
            $allowed = false;
            foreach ($roots as $root) {
                $root = rtrim(str_replace('\\', '/', (string) $root), '/').'/';
                if ($root !== '/' && str_starts_with($path, $root)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) throw new RuntimeException($label.' cannot mutate '.$path.'.');
        }
    }
}
