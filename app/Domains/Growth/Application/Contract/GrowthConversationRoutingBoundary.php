<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthConversationRoutingBoundary
{
    /** @return array<string,mixed> */
    public function routeResponse(string $organizationId,string $responseId,string $correlationId):array;

    /** @return array<string,mixed> */
    public function routingBrief(string $organizationId,string $candidateId):array;
}
