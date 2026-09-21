<?php

declare(strict_types=1);

namespace Kernel\Queue;

use DateTimeImmutable;

final readonly class AsyncOperationProjection
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $type,
        public AsyncOperationStatus $status,
        public int $attempts,
        public int $maxAttempts,
        public string $correlationId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $availableAt = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $finishedAt = null,
        public ?int $progress = null,
        public ?string $actorId = null,
        public ?string $entityType = null,
        public ?string $entityId = null,
        public ?string $workerId = null,
        public ?string $result = null,
        public ?string $error = null,
        public bool $retryScheduled = false,
        public bool $canRetry = false,
    ) {
    }

    public function terminal(): bool
    {
        return in_array(
            $this->status,
            [AsyncOperationStatus::Completed, AsyncOperationStatus::Failed, AsyncOperationStatus::Cancelled],
            true,
        ) && !$this->retryScheduled;
    }
}
