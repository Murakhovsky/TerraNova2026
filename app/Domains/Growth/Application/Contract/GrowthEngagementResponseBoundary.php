<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthEngagementResponseBoundary
{
    /** @return array<string,mixed> */
    public function recordExternalResponse(
        string $organizationId,string $correlationId,string $sourceEventId,string $actionId,string $channel,
        string $body,string $occurredAt,?string $providerReference,?string $threadReference
    ):array;

    /** @return array<string,mixed> */
    public function responseBrief(string $organizationId,string $candidateId):array;
}
