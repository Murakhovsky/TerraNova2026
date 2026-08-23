<?php
declare(strict_types=1);
namespace Kernel\Queue;

final readonly class Job
{
    public function __construct(
        public string $id, public string $organizationId, public string $type, public array $payload,
        public int $attempts, public int $maxAttempts, public int $timeoutSeconds,
        public string $correlationId, public ?string $idempotencyKey = null,
        public string $claimedBy = '',
    ) {}
}
