<?php
declare(strict_types=1);

namespace App\Engineering\Application\Metrics;

interface EngineeringMetricsProviderInterface
{
    /** @return array<string,int|float|null> */
    public function summary(string $organizationId): array;
}
