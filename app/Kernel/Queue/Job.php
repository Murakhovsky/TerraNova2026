<?php
declare(strict_types=1);
namespace Kernel\Queue;

use DateTimeImmutable;

final readonly class Job
{
    public function __construct(
        public string $id, public string $organizationId, public string $type, public array $payload,
        public int $attempts, public int $maxAttempts, public int $timeoutSeconds,
        public string $correlationId, public ?string $idempotencyKey = null,
        public string $claimedBy = '',
        public ?DateTimeImmutable $availableAt = null,
        public float $lockWaitMs = 0.0,
    ) {}

    public function queueWaitMilliseconds(?DateTimeImmutable $now = null): ?float
    {
        if ($this->availableAt === null) {
            return null;
        }

        $now ??= new DateTimeImmutable();
        $elapsed = ((float) $now->format('U.u') - (float) $this->availableAt->format('U.u')) * 1000;
        return max(0.0, $elapsed);
    }
}
