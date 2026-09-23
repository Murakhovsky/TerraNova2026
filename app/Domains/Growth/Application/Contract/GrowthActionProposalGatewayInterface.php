<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\GrowthExecutionAction;

interface GrowthActionProposalGatewayInterface
{
    public function proposeSalesMessage(
        string $organizationId,
        int $actorId,
        string $correlationId,
        string $candidateId,
        string $recommendationId,
        string $dealId,
        string $channel,
        string $body,
        ?float $confidence,
        string $kernelIdempotencyKey,
    ):GrowthExecutionAction;

    public function find(string $organizationId,string $actionId):?GrowthExecutionAction;
}
