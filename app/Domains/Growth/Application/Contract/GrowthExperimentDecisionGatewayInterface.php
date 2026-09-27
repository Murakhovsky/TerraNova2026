<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\ExperimentDecisionDraft;

interface GrowthExperimentDecisionGatewayInterface
{
    /** @param array<string,mixed> $context */
    public function recommend(
        string $organizationId,string $experimentId,string $correlationId,array $context
    ):ExperimentDecisionDraft;
}
