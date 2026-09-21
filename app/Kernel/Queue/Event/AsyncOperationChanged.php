<?php

declare(strict_types=1);

namespace Kernel\Queue\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class AsyncOperationChanged
{
    public const TYPE = 'kernel.queue.operation.changed';

    private function __construct()
    {
    }

    public static function create(
        string $organizationId,
        string $jobId,
        string $status,
        string $correlationId,
    ): DomainEvent {
        return new DomainEvent(
            id: bin2hex(random_bytes(16)),
            organizationId: $organizationId,
            type: self::TYPE,
            aggregateType: 'async_operation',
            aggregateId: $jobId,
            payload: [
                'job_id' => $jobId,
                'status' => $status,
            ],
            metadata: new EventMetadata(
                correlationId: $correlationId !== '' ? $correlationId : $jobId,
                causationId: $jobId,
                actorType: 'SYSTEM',
                actorId: 'queue',
            ),
            occurredAt: new DateTimeImmutable(),
        );
    }
}
