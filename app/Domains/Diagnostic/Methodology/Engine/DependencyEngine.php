<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use Domains\Diagnostic\Methodology\Model\DependencyDefinition;
use Domains\Diagnostic\Methodology\Result\DependencyEvaluation;

final class DependencyEngine
{
    /** @param list<DependencyDefinition> $dependencies @param array<string,bool|string> $availableNodes @return list<DependencyEvaluation> */
    public function evaluate(array $dependencies, array $availableNodes): array
    {
        $incoming=[]; $nodes=[];
        foreach ($dependencies as $dependency) { $incoming[$dependency->target][]=$dependency; $nodes[$dependency->source]=true; $nodes[$dependency->target]=true; }
        $result=[];
        foreach (array_keys($nodes) as $node) {
            $missing=[]; $blocking=[];
            foreach ($incoming[$node]??[] as $dependency) {
                $state=$availableNodes[$dependency->source]??false;
                if ($state===false || $state==='UNKNOWN') $missing[]=$dependency->source;
                if (($dependency->type==='blocks' && $state===true) || $state==='BLOCKED') $blocking[]=$dependency->source;
            }
            $status=$blocking!==[]?'BLOCKED':($missing!==[]?'UNSATISFIED':(isset($availableNodes[$node])?'SATISFIED':'UNKNOWN'));
            if (($availableNodes[$node]??null)==='NOT_APPLICABLE') $status='NOT_APPLICABLE';
            $result[]=new DependencyEvaluation($node,$status,$missing,$blocking);
        }
        return $result;
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
