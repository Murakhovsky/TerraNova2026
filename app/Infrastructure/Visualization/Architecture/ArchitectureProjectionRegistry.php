<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

use InvalidArgumentException;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphProjectionInterface;
use Kernel\Visualization\Graph\GraphProjectionRegistryInterface;
use Kernel\Visualization\Graph\GraphView;
use RuntimeException;

final class ArchitectureProjectionRegistry implements GraphProjectionRegistryInterface
{
    /** @var array<string, GraphProjectionInterface> */
    private array $projections = [];

    /** @var array<string, ArchitectureProjectionDefinition> */
    private array $definitions = [];

    /** @param iterable<ArchitectureProjectionDefinition> $definitions */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            if (!$definition instanceof ArchitectureProjectionDefinition) {
                throw new InvalidArgumentException('Architecture projection registry accepts only definitions.');
            }
            if (isset($this->definitions[$definition->name])) {
                throw new InvalidArgumentException(sprintf('Duplicate architecture projection: %s.', $definition->name));
            }
            $this->definitions[$definition->name] = $definition;
            $this->projections[$definition->name] = new ArchitectureGraphProjection($definition);
        }

        if ($this->definitions === []) {
            throw new InvalidArgumentException('Architecture projection registry cannot be empty.');
        }
    }

    public static function defaults(): self
    {
        $v = ArchitectureGraphVocabulary::class;
        $allTypes = [
            $v::TYPE_KERNEL,
            $v::TYPE_DOMAIN,
            $v::TYPE_CAPABILITY,
            $v::TYPE_EVENT,
            $v::TYPE_ACTION,
            $v::TYPE_AGENT,
            $v::TYPE_SERVICE,
            $v::TYPE_HANDLER,
            $v::TYPE_EXTENSION_POINT,
        ];
        $allRelations = [
            $v::REL_CONTAINS,
            $v::REL_DEPENDS_ON,
            $v::REL_OWNS,
            $v::REL_CONTRIBUTES,
            $v::REL_CONTRIBUTES_TO,
            $v::REL_HANDLED_BY,
            $v::REL_PROPOSES,
        ];

        return new self([
            new ArchitectureProjectionDefinition('system', 'System', [
                $v::TYPE_KERNEL, $v::TYPE_DOMAIN, $v::TYPE_CAPABILITY, $v::TYPE_SERVICE, $v::TYPE_EXTENSION_POINT,
            ], [
                $v::REL_CONTAINS, $v::REL_DEPENDS_ON, $v::REL_OWNS, $v::REL_CONTRIBUTES, $v::REL_CONTRIBUTES_TO,
            ]),
            new ArchitectureProjectionDefinition('runtime', 'Runtime', [
                $v::TYPE_DOMAIN, $v::TYPE_EVENT, $v::TYPE_ACTION, $v::TYPE_AGENT, $v::TYPE_HANDLER,
            ], [$v::REL_OWNS, $v::REL_HANDLED_BY, $v::REL_PROPOSES]),
            new ArchitectureProjectionDefinition('domain', 'Domain', $allTypes, $allRelations, 2),
            new ArchitectureProjectionDefinition('dependencies', 'Dependencies', [
                $v::TYPE_KERNEL, $v::TYPE_DOMAIN,
            ], [$v::REL_CONTAINS, $v::REL_DEPENDS_ON]),
            new ArchitectureProjectionDefinition('events', 'Events', [
                $v::TYPE_DOMAIN, $v::TYPE_EVENT,
            ], [$v::REL_OWNS]),
            new ArchitectureProjectionDefinition('actions', 'Actions', [
                $v::TYPE_DOMAIN, $v::TYPE_ACTION, $v::TYPE_HANDLER, $v::TYPE_AGENT,
            ], [$v::REL_OWNS, $v::REL_HANDLED_BY, $v::REL_PROPOSES]),
            new ArchitectureProjectionDefinition('agents', 'Agents', [
                $v::TYPE_DOMAIN, $v::TYPE_AGENT, $v::TYPE_ACTION,
            ], [$v::REL_OWNS, $v::REL_PROPOSES]),
            new ArchitectureProjectionDefinition('integrations', 'Integrations', [
                $v::TYPE_DOMAIN, $v::TYPE_SERVICE, $v::TYPE_EXTENSION_POINT,
            ], [$v::REL_CONTRIBUTES, $v::REL_CONTRIBUTES_TO]),
            new ArchitectureProjectionDefinition('code', 'Code', [
                $v::TYPE_DOMAIN, $v::TYPE_SERVICE, $v::TYPE_ACTION, $v::TYPE_HANDLER,
            ], [$v::REL_OWNS, $v::REL_CONTRIBUTES, $v::REL_HANDLED_BY]),
        ]);
    }

    public function names(): array
    {
        return array_keys($this->definitions);
    }

    public function descriptions(): array
    {
        $result = [];
        foreach ($this->definitions as $name => $definition) {
            $result[$name] = ['label' => $definition->label];
        }
        return $result;
    }

    public function has(string $name): bool
    {
        return isset($this->projections[$name]);
    }

    public function project(string $name, Graph $graph, GraphView $view): Graph
    {
        $projection = $this->projections[$name]
            ?? throw new RuntimeException(sprintf('Unknown architecture projection: %s.', $name));

        return $projection->project($graph, $view);
    }
}
