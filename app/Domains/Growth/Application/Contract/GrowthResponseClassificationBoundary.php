<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthResponseClassificationBoundary
{
    /** @return array<string,mixed> */
    public function classifyResponse(string $organizationId,string $responseId,string $correlationId):array;
}
