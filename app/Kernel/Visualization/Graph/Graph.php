<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

use InvalidArgumentException;
use RuntimeException;

final class Graph
{
    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var array<string, Edge> */
    private array $edges = [];

    /** @var array<string, Group> */
    private array $groups = [];

    /**
     * @param iterable<Node> $nodes
     * @param iterable<Edge> $edges
     * @param iterable<Group> $groups
     */
    public function __construct(iterable $nodes = [], iterable $edges = [], iterable $groups = [])
    {
        foreach ($groups as $group) {
            if (!$group instanceof Group) {
                throw new InvalidArgumentException('Graph accepts only Group instances in groups.');
            }
            if (isset($this->groups[$group->id])) {
                throw new InvalidArgumentException(sprintf('Duplicate graph group id: %s.', $group->id));
            }
            $this->groups[$group->id] = $group;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                throw new InvalidArgumentException('Graph accepts only Node instances in nodes.');
            }
            if (isset($this->nodes[$node->id])) {
                throw new InvalidArgumentException(sprintf('Duplicate graph node id: %s.', $node->id));
            }
            $this->nodes[$node->id] = $node;
        }

        foreach ($this->groups as $group) {
            if ($group->parent !== null && !isset($this->groups[$group->parent])) {
                throw new InvalidArgumentException(sprintf('Graph group %s references unknown parent %s.', $group->id, $group->parent));
            }
        }

        foreach ($this->nodes as $node) {
            if ($node->parent !== null && !isset($this->nodes[$node->parent]) && !isset($this->groups[$node->parent])) {
                throw new InvalidArgumentException(sprintf('Graph node %s references unknown parent %s.', $node->id, $node->parent));
            }
        }

        foreach ($edges as $edge) {
            if (!$edge instanceof Edge) {
                throw new InvalidArgumentException('Graph accepts only Edge instances in edges.');
            }
            if (isset($this->edges[$edge->id])) {
                throw new InvalidArgumentException(sprintf('Duplicate graph edge id: %s.', $edge->id));
            }
            if (!isset($this->nodes[$edge->source])) {
                throw new InvalidArgumentException(sprintf('Graph edge %s references unknown source node %s.', $edge->id, $edge->source));
            }
            if (!isset($this->nodes[$edge->target])) {
                throw new InvalidArgumentException(sprintf('Graph edge %s references unknown target node %s.', $edge->id, $edge->target));
            }
            $this->edges[$edge->id] = $edge;
        }
    }

    /** @return list<Node> */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return list<Edge> */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /** @return list<Group> */
    public function groups(): array
    {
        return array_values($this->groups);
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function node(string $id): Node
    {
        return $this->nodes[$id] ?? throw new RuntimeException(sprintf('Unknown graph node: %s.', $id));
    }

    public function hasGroup(string $id): bool
    {
        return isset($this->groups[$id]);
    }

    public function group(string $id): Group
    {
        return $this->groups[$id] ?? throw new RuntimeException(sprintf('Unknown graph group: %s.', $id));
    }

    /** @return array{nodes:list<array<string,mixed>>,edges:list<array<string,mixed>>,groups:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'nodes' => array_map(static fn (Node $node): array => $node->toArray(), $this->nodes()),
            'edges' => array_map(static fn (Edge $edge): array => $edge->toArray(), $this->edges()),
            'groups' => array_map(static fn (Group $group): array => $group->toArray(), $this->groups()),
        ];
    }
}
