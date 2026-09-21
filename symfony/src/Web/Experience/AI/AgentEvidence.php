<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

final readonly class AgentEvidence
{
    /** @param array<string,mixed>|string $value */
    public function __construct(
        public string $label,
        public array|string $value,
    ) {
    }
}
