<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\ResearchProposalDraft;

interface GrowthResearchGatewayInterface
{
    /** @param array<string,mixed> $context */
    public function propose(
        string $organizationId,
        string $candidateId,
        string $correlationId,
        array $context,
    ): ResearchProposalDraft;
}
