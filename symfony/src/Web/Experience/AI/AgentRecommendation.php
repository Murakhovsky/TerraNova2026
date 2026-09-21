<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

final readonly class AgentRecommendation
{
    /** @param array<string,mixed> $parameters */
    public function __construct(
        public string $type,
        public ?string $targetType,
        public ?string $targetId,
        public array $parameters = [],
    ) {
    }
}
