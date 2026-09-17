<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphHealthAnalyzerInterface;

final class ArchitectureGraphHealthAnalyzer implements GraphHealthAnalyzerInterface
{
    public function analyze(Graph $graph): array
    {
        $issues = [];
        $nodes = $graph->nodes();
        $edges = $graph->edges();
        $groups = $graph->groups();

        if ($nodes === []) {
            $issues[] = $this->issue(
                'empty_graph',
                'error',
                '',
                'Architecture Graph contains no nodes.',
            );
        }

        $incident = [];
        $nodeParents = [];
        $nodeChildren = [];
        foreach ($nodes as $node) {
            $incident[$node->id] = 0;
            if ($node->parent !== null && $graph->hasNode($node->parent)) {
                $nodeParents[$node->id] = $node->parent;
                $nodeChildren[$node->parent] = true;
            }
        }

        $semanticEdges = [];
        foreach ($edges as $edge) {
            $incident[$edge->source] = ($incident[$edge->source] ?? 0) + 1;
            $incident[$edge->target] = ($incident[$edge->target] ?? 0) + 1;

            if ($edge->source === $edge->target) {
                $issues[] = $this->issue(
                    'self_loop',
                    'error',
                    $edge->source,
                    sprintf('Graph edge %s creates a self-loop.', $edge->id),
                    ['edge_id' => $edge->id, 'relation' => $edge->relation],
                );
            }

            $semanticKey = $edge->source . "\0" . $edge->target . "\0" . $edge->relation;
            if (isset($semanticEdges[$semanticKey])) {
                $issues[] = $this->issue(
                    'duplicate_semantic_edge',
                    'warning',
                    $edge->source,
                    sprintf('Edges %s and %s duplicate the same %s relation.', $semanticEdges[$semanticKey], $edge->id, $edge->relation),
                    ['edge_id' => $edge->id, 'duplicate_of' => $semanticEdges[$semanticKey], 'target' => $edge->target],
                );
            } else {
                $semanticEdges[$semanticKey] = $edge->id;
            }
        }

        foreach ($nodes as $node) {
            if (($incident[$node->id] ?? 0) === 0 && $node->parent === null && !isset($nodeChildren[$node->id])) {
                $issues[] = $this->issue(
                    'isolated_node',
                    'warning',
                    $node->id,
                    sprintf('Node %s is disconnected from the graph topology.', $node->id),
                    ['type' => $node->type],
                );
            }
        }

        foreach ($this->parentCycles($nodeParents) as $cycle) {
            $issues[] = $this->issue(
                'node_parent_cycle',
                'error',
                $cycle[0],
                'Node parent hierarchy contains a cycle: ' . implode(' -> ', $cycle) . '.',
                ['cycle' => $cycle],
            );
        }

        $groupParents = [];
        $groupHasChildren = [];
        foreach ($groups as $group) {
            if ($group->parent !== null) {
                $groupParents[$group->id] = $group->parent;
                $groupHasChildren[$group->parent] = true;
            }
        }
        foreach ($nodes as $node) {
            if ($node->parent !== null && $graph->hasGroup($node->parent)) {
                $groupHasChildren[$node->parent] = true;
            }
        }

        foreach ($this->parentCycles($groupParents) as $cycle) {
            $issues[] = $this->issue(
                'group_parent_cycle',
                'error',
                'group:' . $cycle[0],
                'Group parent hierarchy contains a cycle: ' . implode(' -> ', $cycle) . '.',
                ['cycle' => $cycle],
            );
        }

        foreach ($groups as $group) {
            if (!isset($groupHasChildren[$group->id])) {
                $issues[] = $this->issue(
                    'empty_group',
                    'info',
                    'group:' . $group->id,
                    sprintf('Group %s has no direct child nodes or groups.', $group->id),
                );
            }
        }

        $errors = $this->countSeverity($issues, 'error');
        $warnings = $this->countSeverity($issues, 'warning');
        $info = $this->countSeverity($issues, 'info');

        return [
            'status' => $errors > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'healthy'),
            'total' => count($issues),
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string,string> $parents
     * @return list<list<string>>
     */
    private function parentCycles(array $parents): array
    {
        $cycles = [];
        $seenCycles = [];

        foreach (array_keys($parents) as $start) {
            $path = [];
            $positions = [];
            $current = $start;

            while (isset($parents[$current])) {
                if (isset($positions[$current])) {
                    $cycle = array_slice($path, $positions[$current]);
                    $keyParts = $cycle;
                    sort($keyParts, SORT_STRING);
                    $key = implode('|', $keyParts);
                    if (!isset($seenCycles[$key])) {
                        $seenCycles[$key] = true;
                        $cycles[] = $cycle;
                    }
                    break;
                }

                $positions[$current] = count($path);
                $path[] = $current;
                $current = $parents[$current];
            }
        }

        return $cycles;
    }

    /** @param list<array{code:string,severity:string,node_id:string,message:string,metadata:array<string,mixed>}> $issues */
    private function countSeverity(array $issues, string $severity): int
    {
        return count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === $severity));
    }

    /** @param array<string,mixed> $metadata */
    private function issue(string $code, string $severity, string $nodeId, string $message, array $metadata = []): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'node_id' => $nodeId,
            'message' => $message,
            'metadata' => $metadata,
        ];
    }
}
