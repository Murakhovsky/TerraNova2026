<?php
declare(strict_types=1);

namespace Infrastructure\Audit;

use DateTimeImmutable;
use Kernel\Tool\Contract\ToolAuditInterface;
use Kernel\Tool\Model\ToolExecution;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Model\ActivityStatus;
use Platform\Audit\Model\Actor;
use Platform\Audit\Model\ResourceReference;
use Platform\Audit\Service\AuditRecorder;

final readonly class PlatformToolAudit implements ToolAuditInterface
{
    public function __construct(private AuditRecorder $audit)
    {
    }

    public function record(ToolExecution $execution): void
    {
        $invocation = $execution->invocation;
        $result = $execution->result();
        $status = match ($execution->status()->value) {
            'completed' => ActivityStatus::SUCCESS,
            'denied' => ActivityStatus::DENIED,
            'failed' => ActivityStatus::FAILURE,
            default => ActivityStatus::STARTED,
        };
        $requestedBy = $invocation->requestedBy();

        $this->audit->record(new ActivityRecord(
            id: $execution->id,
            organizationId: $invocation->organizationId(),
            actor: new Actor($requestedBy === null ? 'system' : 'user', $requestedBy?->value() ?? 'system'),
            action: 'tool.execute',
            resource: new ResourceReference('tool', $invocation->toolName()),
            input: $invocation->input(),
            output: $result?->output() ?? [],
            agent: null,
            tool: $invocation->toolName(),
            workflow: null,
            durationMs: null,
            cost: null,
            costUnit: null,
            status: $status,
            error: $execution->error(),
            correlationId: $invocation->correlationId(),
            timestamp: new DateTimeImmutable(),
            metadata: ['attempts' => $execution->attempts(), 'tool_effect' => $execution->definition->effect()->value],
        ));
    }
}
