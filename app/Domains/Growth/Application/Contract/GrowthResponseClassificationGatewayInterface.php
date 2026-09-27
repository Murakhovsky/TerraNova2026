<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\GrowthResponseClassificationDraft;

interface GrowthResponseClassificationGatewayInterface
{
    /** @param array<string,mixed> $context */
    public function classify(string $organizationId,string $correlationId,array $context):GrowthResponseClassificationDraft;
}
