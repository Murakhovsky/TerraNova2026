<?php
declare(strict_types=1);
namespace Kernel\Queue\Contract;

use Kernel\Queue\Job;

interface JobQueueInterface
{
    public function enqueue(string $organizationId, string $type, array $payload, string $correlationId, ?string $idempotencyKey = null, int $maxAttempts = 5, int $timeoutSeconds = 60): string;
    public function claim(string $workerId): ?Job;
    public function complete(Job $job): void;
    public function fail(Job $job, string $error): void;
    public function recoverTimedOut(): int;
    public function replayDead(?string $organizationId = null, ?string $jobId = null): int;
}
