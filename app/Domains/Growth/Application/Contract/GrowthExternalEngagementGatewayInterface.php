<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\GrowthExternalEngagementDelivery;

interface GrowthExternalEngagementGatewayInterface
{
    /** @param array<string,mixed> $metadata */
    public function queueLinkedIn(
        string $organizationId,
        string $recipientProfile,
        ?string $recipientName,
        string $body,
        string $correlationId,
        string $idempotencyKey,
        array $metadata=[],
    ):GrowthExternalEngagementDelivery;

    /** @param array<string,mixed> $metadata */
    public function queueCall(
        string $organizationId,
        string $recipientPhone,
        ?string $recipientName,
        string $callBrief,
        string $correlationId,
        string $idempotencyKey,
        array $metadata=[],
    ):GrowthExternalEngagementDelivery;
}
