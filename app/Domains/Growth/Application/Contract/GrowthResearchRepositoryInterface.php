<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\ResearchProposal;

interface GrowthResearchRepositoryInterface
{
    /** @param array<string,mixed> $contextSnapshot */
    public function createRun(
        string $organizationId,string $runId,string $candidateId,string $promptVersion,string $schemaVersion,
        array $contextSnapshot,int $actorId
    ): void;

    /** @return array<string,mixed>|null */
    public function viewRun(string $organizationId,string $runId): ?array;

    public function completeRun(
        string $organizationId,string $runId,string $proposalId,string $provider,string $model,
        ?int $inputTokens,?int $outputTokens,?float $costAmount,?string $costCurrency
    ): void;

    public function failRun(string $organizationId,string $runId,string $errorSummary): void;

    public function createProposal(ResearchProposal $proposal,string $runId,int $actorId): void;

    public function lockProposal(string $organizationId,string $proposalId): ResearchProposal;

    /** @return array<string,mixed>|null */
    public function viewProposal(string $organizationId,string $proposalId): ?array;

    /** @return array<string,mixed>|null */
    public function latestProposal(string $organizationId,string $candidateId): ?array;

    public function acceptProposal(string $organizationId,string $proposalId,int $actorId): void;
}
