<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

final readonly class AgentWarning
{
    public function __construct(
        public string $message,
        public ?string $code = null,
    ) {
    }
}
