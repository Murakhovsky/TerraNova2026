<?php
declare(strict_types=1);

namespace Infrastructure\Audit;

use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Platform\Audit\Contract\AuditSinkInterface;
use Platform\Audit\Model\ActivityRecord;

final readonly class KernelAuditSink implements AuditSinkInterface
{
    public function __construct(private AuditRepositoryInterface $repository)
    {
    }

    public function append(ActivityRecord $record): void
    {
        $this->repository->append(new AuditEntry(
            id: $record->id,
            organizationId: $record->organizationId->value(),
            category: 'platform_activity',
            actorType: $record->actor->type,
            actorId: $record->actor->id,
            subjectType: $record->resource->type,
            subjectId: $record->resource->id,
            reason: $record->error,
            data: $record->toArray(),
            correlationId: $record->correlationId,
            createdAt: $record->timestamp,
        ));
    }
}
