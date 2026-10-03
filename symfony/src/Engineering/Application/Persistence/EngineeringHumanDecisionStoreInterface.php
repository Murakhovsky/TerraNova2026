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

    /** @return array<string,mixed> */
    public function get(string $requestId): array;

    /** @return array{feature_id:string,workflow_id:string,decision_id:string} */
    public function answer(string $requestId, string $selectedOption, ?string $comment, string $decidedBy): array;

    /** @return list<array<string,mixed>> */
    public function openForFeature(string $featureId): array;
}
