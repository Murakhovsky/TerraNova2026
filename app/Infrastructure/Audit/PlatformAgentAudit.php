<?php
declare(strict_types=1);

namespace Infrastructure\Audit;

use DateTimeImmutable;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentAuditInterface;
use Kernel\Agent\Model\AgentAuditEvent;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Audit\Contract\AgentTraceRepositoryInterface;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Model\ActivityStatus;
use Platform\Audit\Model\Actor;
use Platform\Audit\Model\AgentRunHistory;
use Platform\Audit\Model\ResourceReference;
use Platform\Audit\Model\TraceEvent;
use Platform\Audit\Model\TraceEventType;
use Platform\Audit\Service\AuditRecorder;

final readonly class PlatformAgentAudit implements AgentAuditInterface
{
    public function __construct(
        private AgentTraceRepositoryInterface $traces,
        private AuditRecorder $audit,
    ) {
    }

    public function record(
        string $runId,
        AgentDefinition $agent,
        AgentInvocation $invocation,
        AgentAuditEvent $event,
        array $payload = [],
        ?int $durationMs = null,
        ?float $cost = null,
        ?string $costUnit = null,
        ?string $error = null,
    ): void {
        $organizationId = OrganizationId::fromString($invocation->organizationId);
        $history = $this->traces->find($runId) ?? new AgentRunHistory($runId, $organizationId, $agent->name, $invocation->correlationId);
        $status = $event === AgentAuditEvent::FAILURE ? ActivityStatus::FAILURE : ActivityStatus::SUCCESS;
        $traceType = match ($event) {
            AgentAuditEvent::REASONING_REQUEST => TraceEventType::REASONING_REQUEST,
            AgentAuditEvent::REASONING_RESULT => TraceEventType::REASONING_RESULT,
            AgentAuditEvent::DECISION => TraceEventType::DECISION,
            AgentAuditEvent::ACTION => TraceEventType::ACTION,
            AgentAuditEvent::RESULT, AgentAuditEvent::FAILURE => TraceEventType::RESULT,
        };
        $sequence = count($history->events()) + 1;
        $history->append(new TraceEvent($sequence, $traceType, $payload, $status, new DateTimeImmutable(), $durationMs, $cost, $costUnit, $error));
        $this->traces->save($history);

        $isInput = in_array($event, [AgentAuditEvent::REASONING_REQUEST, AgentAuditEvent::ACTION], true);
        $this->audit->record(new ActivityRecord(
            id: $runId . ':' . $sequence,
            organizationId: $organizationId,
            actor: new Actor('agent', $agent->name),
            action: 'agent.' . $event->value,
            resource: new ResourceReference($invocation->subjectType, $invocation->subjectId),
            input: $isInput ? $payload : [],
            output: $isInput ? [] : $payload,
            agent: $agent->name,
            tool: null,
            workflow: null,
            durationMs: $durationMs,
            cost: $cost,
            costUnit: $costUnit,
            status: $status,
            error: $error,
            correlationId: $invocation->correlationId,
            timestamp: new DateTimeImmutable(),
            metadata: ['agent_run_id' => $runId, 'trace_sequence' => $sequence],
        ));
    }
}
