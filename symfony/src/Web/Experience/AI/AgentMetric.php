<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

final readonly class AgentMetric
{
    public function __construct(
        public string $name,
        public int|float|string $value,
        public ?string $unit = null,
    ) {
    }
}
