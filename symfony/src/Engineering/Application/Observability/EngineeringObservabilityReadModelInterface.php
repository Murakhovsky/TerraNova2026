<?php
declare(strict_types=1);

namespace App\Engineering\Application\Observability;

interface EngineeringObservabilityReadModelInterface
{
    /** @return array<string,mixed> */
    public function usageForFeature(string $featureId): array;

    /** @param list<string> $featureIds @return array<string,array<string,mixed>> */
    public function usageForFeatures(array $featureIds): array;

    /** @return list<array<string,mixed>> */
    public function invocationsForFeature(string $featureId): array;

    /** @param list<string> $featureIds @return array<string,array<string,mixed>> */
    public function workspaceFactsForFeatures(array $featureIds): array;
}
