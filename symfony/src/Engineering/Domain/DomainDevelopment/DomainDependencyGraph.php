<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

use LogicException;

final class DomainDependencyGraph
{
    /** @var array<string,array<string,mixed>> */
    private array $features = [];

    /** @var array<string,list<array{feature_id:string,depends_on_feature_id:string,dependency_type:string}>> */
    private array $incoming = [];

    /** @param list<array<string,mixed>> $features
     *  @param list<array<string,mixed>> $dependencies
     */
    public function __construct(array $features, array $dependencies)
    {
        foreach ($features as $feature) {
            $id = trim((string) ($feature['id'] ?? ''));
            if ($id === '') throw new LogicException('Domain feature id is required.');
            if (isset($this->features[$id])) throw new LogicException('Duplicate domain feature id '.$id.'.');
            $this->features[$id] = $feature;
            $this->incoming[$id] = [];
        }

        foreach ($dependencies as $dependency) {
            $featureId = trim((string) ($dependency['feature_id'] ?? ''));
            $dependsOn = trim((string) ($dependency['depends_on_feature_id'] ?? ''));
            $type = DomainDependencyType::from((string) ($dependency['dependency_type'] ?? DomainDependencyType::REQUIRES->value));
            if (!isset($this->features[$featureId], $this->features[$dependsOn])) {
                throw new LogicException('Domain dependency references an unknown feature.');
            }
            if ($featureId === $dependsOn) throw new LogicException('Domain feature cannot depend on itself.');
            if ($type->blocksScheduling()) {
                $this->incoming[$featureId][] = [
                    'feature_id' => $featureId,
                    'depends_on_feature_id' => $dependsOn,
                    'dependency_type' => $type->value,
                ];
            }
        }

        $this->topologicalOrder();
    }

    /** @return list<string> */
    public function topologicalOrder(): array
    {
        $indegree = array_fill_keys(array_keys($this->features), 0);
        $outgoing = array_fill_keys(array_keys($this->features), []);
        foreach ($this->incoming as $featureId => $dependencies) {
            foreach ($dependencies as $dependency) {
                ++$indegree[$featureId];
                $outgoing[$dependency['depends_on_feature_id']][] = $featureId;
            }
        }

        $queue = array_keys(array_filter($indegree, static fn (int $value): bool => $value === 0));
        sort($queue);
        $result = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $result[] = $current;
            foreach ($outgoing[$current] as $dependent) {
                --$indegree[$dependent];
                if ($indegree[$dependent] === 0) {
                    $queue[] = $dependent;
                    sort($queue);
                }
            }
        }

        if (count($result) !== count($this->features)) {
            throw new LogicException('Domain feature dependency graph contains a cycle.');
        }

        return $result;
    }

    /** @return list<string> */
    public function readyFeatureIds(): array
    {
        $ready = [];
        foreach ($this->topologicalOrder() as $featureId) {
            $feature = $this->features[$featureId];
            $status = DomainFeatureStatus::from((string) ($feature['status'] ?? DomainFeatureStatus::NOT_STARTED->value));
            if (!$status->schedulable()) continue;

            $dependenciesComplete = true;
            foreach ($this->incoming[$featureId] as $dependency) {
                $upstream = $this->features[$dependency['depends_on_feature_id']];
                if (($upstream['status'] ?? null) !== DomainFeatureStatus::COMPLETED->value) {
                    $dependenciesComplete = false;
                    break;
                }
            }
            if ($dependenciesComplete) $ready[] = $featureId;
        }
        return $ready;
    }

    /** @return list<string> */
    public function directDependencyIds(string $featureId): array
    {
        if (!isset($this->features[$featureId])) throw new LogicException('Unknown domain feature '.$featureId.'.');
        return array_values(array_unique(array_map(
            static fn (array $dependency): string => $dependency['depends_on_feature_id'],
            $this->incoming[$featureId],
        )));
    }
}
