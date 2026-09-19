<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Cytoscape;

use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphPayloadMapperInterface;
use Kernel\Visualization\Graph\Group;
use Kernel\Visualization\Graph\Node;

final readonly class CytoscapeGraphMapper
{
    /**
     * @return array{
     *   elements:list<array{group:string,data:array<string,mixed>,classes:string}>,
     *   summary:array{nodes:int,edges:int,groups:int,node_types:array<string,int>,relations:array<string,int>,domains:list<array{id:string,label:string,module_id:string}>}
     * }
     */
    public function map(Graph $graph): array
    {
        $elements = [];
        $nodeTypes = [];
        $relations = [];
        $domains = [];

        foreach ($graph->groups() as $group) {
            $elements[] = $this->groupElement($group);
        }

        foreach ($graph->nodes() as $node) {
            $elements[] = $this->nodeElement($node);
            $nodeTypes[$node->type] = ($nodeTypes[$node->type] ?? 0) + 1;

            if ($node->type === 'domain') {
                $domains[] = [
                    'id' => $node->id,
                    'label' => $node->label,
                    'module_id' => (string) ($node->metadata['module_id'] ?? ''),
                ];
            }
        }

        foreach ($graph->edges() as $edge) {
            $elements[] = $this->edgeElement($edge);
            $relations[$edge->relation] = ($relations[$edge->relation] ?? 0) + 1;
        }

        ksort($nodeTypes);
        ksort($relations);
        usort($domains, static fn (array $left, array $right): int => strcmp($left['label'], $right['label']));

        return [
            'elements' => $elements,
            'summary' => [
                'nodes' => count($graph->nodes()),
                'edges' => count($graph->edges()),
                'groups' => count($graph->groups()),
                'node_types' => $nodeTypes,
                'relations' => $relations,
                'domains' => $domains,
            ],
        ];
    }

    /** @return array{group:string,data:array<string,mixed>,classes:string} */
    private function groupElement(Group $group): array
    {
        $data = [
            'id' => $group->id,
            'label' => $group->label,
            'type' => 'group',
            'metadata' => $group->metadata,
            'is_group' => true,
        ];
        if ($group->parent !== null) {
            $data['parent'] = $group->parent;
        }

        return [
            'group' => 'nodes',
            'data' => $data,
            'classes' => 'graph-group',
        ];
    }

    /** @return array{group:string,data:array<string,mixed>,classes:string} */
    private function nodeElement(Node $node): array
    {
        $data = [
            'id' => $node->id,
            'label' => $node->label,
            'type' => $node->type,
            'metadata' => $node->metadata,
        ];
        if ($node->parent !== null) {
            $data['parent'] = $node->parent;
        }

        return [
            'group' => 'nodes',
            'data' => $data,
            'classes' => 'node-type-' . $this->classToken($node->type),
        ];
    }

    /** @return array{group:string,data:array<string,mixed>,classes:string} */
    private function edgeElement(Edge $edge): array
    {
        return [
            'group' => 'edges',
            'data' => [
                'id' => $edge->id,
                'source' => $edge->source,
                'target' => $edge->target,
                'relation' => $edge->relation,
                'label' => $edge->relation,
                'metadata' => $edge->metadata,
            ],
            'classes' => 'edge-relation-' . $this->classToken($edge->relation),
        ];
    }

    private function classToken(string $value): string
    {
        $token = strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', $value));
        return trim($token, '-') ?: 'unknown';
    }
}
