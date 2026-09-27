<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthCollectorIncidentAlertGatewayInterface
{
    /** @param array<string,mixed> $incident @param array<string,mixed> $subscription */
    public function queue(
        string $organizationId,
        array $subscription,
        array $incident,
        string $transition,
        string $correlationId,
    ):void;
}
