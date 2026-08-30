<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use Domains\Diagnostic\Methodology\Model\DependencyDefinition;

final class DependencyEngine
{
    /** @param list<DependencyDefinition> $dependencies @return list<DependencyDefinition> */
    public function evaluate(array $dependencies, array $availableNodes): array
    {
        return array_values($dependencies);
    }

    /** @param list<DependencyDefinition> $dependencies @return list<DependencyDefinition> */
    public function downstream(string $node, array $dependencies): array
    {
        return $this->reachable($node, $dependencies, true);
    }

    /** @param list<DependencyDefinition> $dependencies @return list<DependencyDefinition> */
    public function upstream(string $node, array $dependencies): array
    {
        return $this->reachable($node, $dependencies, false);
    }

    /** @param list<DependencyDefinition> $dependencies @return list<DependencyDefinition> */
    private function reachable(string $node, array $dependencies, bool $forward): array
    {
        $result = []; $visitedNodes = [$node => true]; $queue = [$node];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($dependencies as $index => $dependency) {
                $from = $forward ? $dependency->source : $dependency->target;
                $to = $forward ? $dependency->target : $dependency->source;
                if ($from !== $current) continue;
                $result[$index] = $dependency;
                if (!isset($visitedNodes[$to])) { $visitedNodes[$to] = true; $queue[] = $to; }
            }
        }
        ksort($result);
        return array_values($result);
    }
}
