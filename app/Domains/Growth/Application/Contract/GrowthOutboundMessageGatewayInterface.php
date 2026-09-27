<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\GrowthOutboundDelivery;

interface GrowthOutboundMessageGatewayInterface
{
    /** @param array<string,mixed> $metadata */
    public function queueEmail(
        string $organizationId,
        string $recipientAddress,
        ?string $recipientName,
        string $body,
        ?string $subject,
        string $locale,
        string $correlationId,
        string $idempotencyKey,
        array $metadata=[],
    ):GrowthOutboundDelivery;
}
