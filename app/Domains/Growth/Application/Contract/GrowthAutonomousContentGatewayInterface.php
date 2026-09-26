<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\AutonomousContentDraft;

interface GrowthAutonomousContentGatewayInterface
{
    /** @param array<string,mixed> $context */
    public function draft(string $organizationId,string $candidateId,string $recommendationId,string $correlationId,array $context):AutonomousContentDraft;
}
