<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\EngagementRecommendationDraft;

interface GrowthEngagementGatewayInterface
{
    /** @param array<string,mixed> $context */
    public function recommend(string $organizationId,string $candidateId,string $correlationId,array $context):EngagementRecommendationDraft;
}
