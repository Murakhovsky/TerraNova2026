<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

/**
 * Query/collection port for the public Property conversion funnel.
 * The physical telemetry store is a shared Infrastructure capability.
 */
interface PropertyFunnelAnalyticsInterface
{
    public function recordPublicEvent(array $input, ?array $user = null): bool;

    public function report(int $days = 30): array;
}
