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
        $actorType = match (strtolower($record->actor->type)) {
            'user', 'human' => 'USER',
            'agent' => 'AGENT',
            'worker' => 'WORKER',
            'integration' => 'INTEGRATION',
            default => 'SYSTEM',
        };

        $this->repository->append(new AuditEntry(
            id: $record->id,
            organizationId: $record->organizationId->value(),
            category: 'platform_activity',
            actorType: $actorType,
            actorId: $record->actor->id,
            subjectType: $record->resource->type,
            subjectId: $record->resource->id,
            reason: $record->error,
            data: [
                'action' => $record->action,
                'input_references' => $record->input,
                'changes' => [
                    'status' => $record->status->value,
                    'duration_ms' => $record->durationMs,
                    'cost' => $record->cost,
                    'cost_unit' => $record->costUnit,
                    'agent' => $record->agent,
                    'tool' => $record->tool,
                    'workflow' => $record->workflow,
                ],
                'result' => $record->output,
                'metadata' => array_merge($record->metadata, [
                    'platform_activity' => true,
                    'source' => $record->source->value,
                    'actor_kind' => $record->actor->kind()->value,
                ]),
            ],
            correlationId: $record->correlationId,
            createdAt: $record->timestamp,
        ));
    }
}
