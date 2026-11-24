<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

/** Sales-native historical terminal evidence and current close-date forecast. */
interface SalesCommercialAnalyticsReadPort
{
    public function outcomes(string $org, ?int $owner, ?string $pipeline, string $from, string $to): array;
    public function forecast(string $org, ?int $owner, ?string $pipeline, string $from, string $to): array;
}
