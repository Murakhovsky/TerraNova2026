<?php
declare(strict_types=1);

namespace Domains\Analytics\Application\Contract;

interface AnalyticsServiceInterface
{
    public function recordPublicEvent(array $input, ?array $user = null): bool;
    public function report(int $days = 30): array;
}
