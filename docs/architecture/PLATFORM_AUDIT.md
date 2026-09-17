# COS Platform Audit / Observability

`Kernel\\Audit` remains the stable durable audit primitive/repository contract. `Platform\\Audit` is the richer runtime recording layer used by Agent, Tool, Workflow and domain/application orchestration.

## Activity record

Every record can carry:

- Actor
- Action
- Resource
- Input / Output
- Agent / Tool / Workflow identity
- Duration
- Cost + unit
- Status / Error
- Correlation id
- Timestamp

`Infrastructure\\Audit\\KernelAuditSink` bridges the richer Platform record into the existing `Kernel\\Audit\\AuditEntry`, preserving backward compatibility.

## Agent history

`AgentRunHistory` is an ordered trace. Canonical event vocabulary includes:

```text
Reasoning request
→ Reasoning result
→ Tool call
→ Tool result
→ Decision
→ Action
→ Result
```

Workflow steps can appear in the same trace. The sequence is explicit, source payload is structured, and duration/cost can be attached to each event.

## Audit vs observability

Audit is durable evidence of what happened and who/what caused it. Observability is operational telemetry for debugging and performance. Existing `Kernel\\Observability\\StructuredLoggerInterface` remains logging infrastructure; it is not a substitute for the durable audit trail.
