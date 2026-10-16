<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use Platform\Workflow\Service\WorkflowDependencyGraph;

/**
 * Compatibility boundary. Shared DAG validation lives in Platform Workflow;
 * Goal/Plan callers retain their existing public API during the cutover.
 */
final readonly class FederationStepDependencyGraph
{
    /**
     * @param list<array<string,mixed>> $steps
     * @return array<string,list<string>>
     */
    public function resolve(array $steps): array
    {
        return (new WorkflowDependencyGraph())->resolve($steps);
    }
}
