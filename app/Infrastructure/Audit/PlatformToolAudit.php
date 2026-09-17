<?php
declare(strict_types=1);

namespace Infrastructure\Audit;

use DateTimeImmutable;
use Kernel\Tool\Contract\ToolAuditInterface;
use Kernel\Tool\Model\ToolExecution;
use Platform\Audit\Contract\AgentTraceRepositoryInterface;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Model\ActivityStatus;
use Platform\Audit\Model\Actor;
use Platform\Audit\Model\ResourceReference;
use Platform\Audit\Model\TraceEvent;
use Platform\Audit\Model\TraceEventType;
use Platform\Audit\Service\AuditRecorder;

final readonly class PlatformToolAudit implements ToolAuditInterface
{
    public function __construct(
        private AuditRecorder $audit,
        private ?AgentTraceRepositoryInterface $traces = null,
    ) {
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

        if ($this->traces === null) {
            return;
        }

        $history = $this->traces->findByCorrelationId($invocation->organizationId(), $invocation->correlationId());
        if ($history === null) {
            return;
        }

        $sequence = count($history->events()) + 1;
        $history->append(new TraceEvent($sequence, TraceEventType::TOOL_CALL, [
            'tool' => $invocation->toolName(),
            'input' => $invocation->input(),
        ], ActivityStatus::STARTED, new DateTimeImmutable()));
        $history->append(new TraceEvent($sequence + 1, TraceEventType::TOOL_RESULT, [
            'tool' => $invocation->toolName(),
            'output' => $result?->output() ?? [],
            'attempts' => $execution->attempts(),
        ], $status, new DateTimeImmutable(), error: $execution->error()));
        $this->traces->save($history);
    }
}
