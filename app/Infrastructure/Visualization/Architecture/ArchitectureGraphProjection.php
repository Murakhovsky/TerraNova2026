<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphProjectionInterface;
use Kernel\Visualization\Graph\GraphView;
use Kernel\Visualization\Graph\Group;
use Kernel\Visualization\Graph\Node;

final readonly class ArchitectureGraphProjection implements GraphProjectionInterface
{
    public function __construct(private ArchitectureProjectionDefinition $definition)
    {
    }

    public function project(Graph $graph, GraphView $view): Graph
    {
        $nodeTypes = $this->definition->nodeTypes;
        if ($view->filters->nodeTypes !== []) {
            $nodeTypes = array_values(array_intersect($nodeTypes, $view->filters->nodeTypes));
        }

        $relations = $this->definition->relations;
        if ($view->filters->relations !== []) {
            $relations = array_values(array_intersect($relations, $view->filters->relations));
        }

        /** @var array<string, Node> $nodes */
        $nodes = [];
        foreach ($graph->nodes() as $node) {
            if (in_array($node->type, $nodeTypes, true)) {
                $nodes[$node->id] = $node;
            }
        }

        /** @var array<string, Edge> $candidateEdges */
        $candidateEdges = [];
        foreach ($graph->edges() as $edge) {
            if (
                isset($nodes[$edge->source], $nodes[$edge->target])
                && in_array($edge->relation, $relations, true)
            ) {
                $candidateEdges[$edge->id] = $edge;
            }
        }

        if ($view->focus !== null) {
            $nodes = $this->focusNodes(
                $nodes,
                $candidateEdges,
                $view->focus,
                $view->depth ?? $this->definition->defaultDepth,
            );
        }

        $this->addNodeParents($graph, $nodes);

        $edges = array_values(array_filter(
            $candidateEdges,
            static fn (Edge $edge): bool => isset($nodes[$edge->source], $nodes[$edge->target]),
        ));

        $groups = $this->requiredGroups($graph, $nodes);

        return new Graph(array_values($nodes), $edges, $groups);
    }

    /**
     * @param array<string, Node> $nodes
     * @param array<string, Edge> $edges
     * @return array<string, Node>
     */
    private function focusNodes(array $nodes, array $edges, string $focus, ?int $depth): array
    {
        if (!isset($nodes[$focus])) {
            return [];
        }
        if ($depth === null) {
            return $nodes;
        }

        /** @var array<string, list<string>> $adjacency */
        $adjacency = [];
        foreach ($edges as $edge) {
            $adjacency[$edge->source][] = $edge->target;
            $adjacency[$edge->target][] = $edge->source;
        }

        $seen = [$focus => true];
        $frontier = [$focus];
        for ($level = 0; $level < $depth; $level++) {
            $next = [];
            foreach ($frontier as $nodeId) {
                foreach ($adjacency[$nodeId] ?? [] as $neighbor) {
                    if (isset($seen[$neighbor])) {
                        continue;
                    }
                    $seen[$neighbor] = true;
                    $next[] = $neighbor;
                }
            }
            if ($next === []) {
                break;
            }
            $frontier = $next;
        }

        return array_intersect_key($nodes, $seen);
    }

    /** @param array<string, Node> $nodes */
    private function addNodeParents(Graph $graph, array &$nodes): void
    {
        $pending = array_values($nodes);
        while ($pending !== []) {
            /** @var Node $node */
            $node = array_pop($pending);
            if ($node->parent === null || isset($nodes[$node->parent]) || !$graph->hasNode($node->parent)) {
                continue;
            }
            $parent = $graph->node($node->parent);
            $nodes[$parent->id] = $parent;
            $pending[] = $parent;
        }
    }

    /** @param array<string, Node> $nodes @return list<Group> */
    private function requiredGroups(Graph $graph, array $nodes): array
    {
        /** @var array<string, Group> $groups */
        $groups = [];
        $pending = [];
        foreach ($nodes as $node) {
            if ($node->parent !== null && $graph->hasGroup($node->parent)) {
                $pending[] = $node->parent;
            }
        }

        while ($pending !== []) {
            $groupId = array_pop($pending);
            if (isset($groups[$groupId]) || !$graph->hasGroup($groupId)) {
                continue;
            }
            $group = $graph->group($groupId);
            $groups[$groupId] = $group;
            if ($group->parent !== null) {
                $pending[] = $group->parent;
            }
        }

        return array_values($groups);
    }
}
