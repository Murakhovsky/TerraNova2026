<?php
declare(strict_types=1);

namespace App\Engineering\Application\Policy;

final class RuntimeCapabilityRegistry
{
    /** @return array<string,bool|string> */
    public function forRuntime(string $runtime): array
    {
        return match ($runtime) {
            'EngineeringRuntime' => [
                'GitHub' => true,
                'CI' => true,
                'Repository' => true,
                'TestRunner' => true,
                'StaticAnalysis' => true,
                'DatabaseSchemaRead' => true,
                'FeatureRuntime' => true,
                'DomainScheduler' => true,
                'IntegrationBranch' => true,
                'ProductionExecution' => false,
            ],
            default => [],
        };
    }
}
