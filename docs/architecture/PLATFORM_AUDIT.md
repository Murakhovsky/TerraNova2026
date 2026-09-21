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
- Source provenance (`HUMAN`, `AGENT`, `TOOL`, `WORKFLOW`, `INTEGRATION`, `WORKER`, `SYSTEM`)
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


## Canonical history

Wave 12.21 adds a tenant-scoped history read contract over the same durable `cos_audit_log`; it does not create a second audit store.

`ActivityHistoryRepositoryInterface` supports organization history, resource history and correlation history. Every query requires `OrganizationId`. Human/agent/system identity is exposed separately from source provenance, so a human-triggered tool call remains attributable to the human actor while its source is `TOOL`.
