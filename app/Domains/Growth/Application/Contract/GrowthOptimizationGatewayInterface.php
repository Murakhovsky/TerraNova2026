<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\LearningOptimizationDraft;

interface GrowthOptimizationGatewayInterface
{
    /** @param array<string,mixed> $context */
    public function recommend(string $organizationId,string $correlationId,array $context):LearningOptimizationDraft;
}
