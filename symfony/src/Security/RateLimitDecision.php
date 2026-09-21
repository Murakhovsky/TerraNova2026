<?php

declare(strict_types=1);

namespace App\Security;

use DateTimeImmutable;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public ?DateTimeImmutable $retryAt = null,
    ) {
    }

    public function retryAfterSeconds(DateTimeImmutable $now = new DateTimeImmutable()): int
    {
        if ($this->retryAt === null) {
            return 0;
        }

        return max(0, $this->retryAt->getTimestamp() - $now->getTimestamp());
    }
}
