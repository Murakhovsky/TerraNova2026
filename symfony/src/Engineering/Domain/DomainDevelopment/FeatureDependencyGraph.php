<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

use InvalidArgumentException;

final class FeatureDependencyGraph
{
    /**
     * @param list<array<string,mixed>> $features
     * @param list<array<string,mixed>> $dependencies
     */
    public function assertValid(array $features, array $dependencies): void
    {
        $keys = [];
        foreach ($features as $feature) {
            $key = trim((string) ($feature['key'] ?? ''));
            if ($key === '') throw new InvalidArgumentException('Domain feature key is required.');
            if (isset($keys[$key])) throw new InvalidArgumentException('Duplicate domain feature key: '.$key);
            $keys[$key] = true;
        }

        $incoming = array_fill_keys(array_keys($keys), 0);
        $outgoing = array_fill_keys(array_keys($keys), []);
        foreach ($dependencies as $dependency) {
            $feature = trim((string) ($dependency['feature_key'] ?? ''));
            $requires = trim((string) ($dependency['depends_on_key'] ?? ''));
            if (!isset($keys[$feature]) || !isset($keys[$requires])) {
                throw new InvalidArgumentException('Dependency references unknown feature: '.$feature.' -> '.$requires);
            }
            if ($feature === $requires) throw new InvalidArgumentException('Feature cannot depend on itself: '.$feature);
            $outgoing[$requires][] = $feature;
            ++$incoming[$feature];
        }

        $queue = [];
        foreach ($incoming as $key => $count) if ($count === 0) $queue[] = $key;
        $visited = 0;
        while ($queue !== []) {
            $key = array_shift($queue);
            ++$visited;
            foreach ($outgoing[$key] as $dependent) {
                --$incoming[$dependent];
                if ($incoming[$dependent] === 0) $queue[] = $dependent;
            }
        }

        if ($visited !== count($keys)) {
            throw new InvalidArgumentException('Domain feature dependency graph contains a cycle.');
        }
    }

    /**
     * @param list<array<string,mixed>> $features
     * @param list<array<string,mixed>> $dependencies
     * @return list<string>
     */
    public function ready(array $features, array $dependencies): array
    {
        $status = [];
        foreach ($features as $feature) {
            $status[(string) $feature['feature_key']] = (string) $feature['status'];
        }

        $requires = [];
        foreach ($dependencies as $dependency) {
            $requires[(string) $dependency['feature_key']][] = (string) $dependency['depends_on_key'];
        }

        $ready = [];
        foreach ($features as $feature) {
            $key = (string) $feature['feature_key'];
            if (!in_array((string) $feature['status'], [
                EngineeringDomainFeatureStatus::NOT_STARTED->value,
                EngineeringDomainFeatureStatus::READY->value,
                EngineeringDomainFeatureStatus::WAITING->value,
            ], true)) continue;

            $allComplete = true;
            foreach ($requires[$key] ?? [] as $required) {
                if (($status[$required] ?? null) !== EngineeringDomainFeatureStatus::COMPLETED->value) {
                    $allComplete = false;
                    break;
                }
            }
            if ($allComplete) $ready[] = $key;
        }

        return $ready;
    }
}
