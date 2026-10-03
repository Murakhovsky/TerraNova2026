<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

interface EngineeringHumanDecisionStoreInterface
{
    public function create(
        string $featureId,
        string $workflowId,
        string $type,
        string $question,
        string $reason,
        array $options,
        array $evidence,
        bool $blocking = true,
        ?string $recommendedOption = null,
    ): string;

    /** @return list<array<string,mixed>> */
    public function openForFeature(string $featureId): array;
}
