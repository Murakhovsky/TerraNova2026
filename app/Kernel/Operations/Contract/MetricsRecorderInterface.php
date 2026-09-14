<?php
declare(strict_types=1);

namespace Kernel\Operations\Contract;

interface MetricsRecorderInterface
{
    public function record(string $metric, float $value, ?string $organizationId = null, array $labels = []): void;
}
