<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Validates the immutable approved Plan DAG without dispatching anything.
 * Legacy steps with no depends_on retain strict sequential semantics.
 */
final readonly class FederationStepDependencyGraph
{
    /**
     * @param list<array<string,mixed>> $steps
     * @return array<string,list<string>> Step ID => prerequisite Step IDs
     */
    public function resolve(array $steps): array
    {
        if ($steps === [] || !array_is_list($steps) || count($steps) > 100) {
            throw new DomainException('Federation Plan requires 1..100 ordered steps.');
        }

        $graph = [];
        $previous = null;
        foreach ($steps as $step) {
            if (!is_array($step) || !is_string($step['id'] ?? null)
                || !preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $step['id'])
                || array_key_exists($step['id'], $graph)) {
                throw new DomainException('Invalid or duplicate Federation Plan step ID.');
            }
            $id = $step['id'];
            $dependencies = array_key_exists('depends_on', $step)
                ? $step['depends_on']
                : ($previous === null ? [] : [$previous]);
            if (!is_array($dependencies) || !array_is_list($dependencies)
                || count($dependencies) > 100) {
                throw new DomainException('Federation dependencies must be an ordered list.');
            }
            $seen = [];
            foreach ($dependencies as $dependency) {
                if (!is_string($dependency) || $dependency === ''
                    || $dependency === $id || isset($seen[$dependency])) {
                    throw new DomainException('Federation Plan has an invalid, duplicate or self dependency.');
                }
                $seen[$dependency] = true;
            }
            $graph[$id] = $dependencies;
            $previous = $id;
        }

        foreach ($graph as $dependencies) {
            foreach ($dependencies as $dependency) {
                if (!array_key_exists($dependency, $graph)) {
                    throw new DomainException('Federation Plan references an unknown prerequisite.');
                }
            }
        }

        $visiting = [];
        $visited = [];
        $walk = static function (string $id) use (&$walk, &$visiting, &$visited, $graph): void {
            if (isset($visiting[$id])) {
                throw new DomainException('Federation Plan contains a dependency cycle.');
            }
            if (isset($visited[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach ($graph[$id] as $dependency) {
                $walk($dependency);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
        };
        foreach (array_keys($graph) as $id) {
            $walk($id);
        }

        return $graph;
    }
}
