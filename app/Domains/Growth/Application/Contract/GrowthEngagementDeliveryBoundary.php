<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthEngagementDeliveryBoundary
{
    /** @return array<string,mixed> */
    public function recordExternalStatus(
        string $organizationId,
        string $correlationId,
        string $sourceEventId,
        string $actionId,
        string $channel,
        string $status,
        string $occurredAt,
        ?string $providerReference,
        ?string $reasonCode,
        ?string $reasonText,
    ):array;
}
