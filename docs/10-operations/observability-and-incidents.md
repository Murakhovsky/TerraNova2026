---
title: Observability & Incident Signals
description: Logs, metrics, traces, queue signals and incident triage model for COS operations.
status: active
updated: 2026-09-15
kind: operations
---

# Observability & Incident Signals

COS observability має дозволяти перейти від «щось не працює» до конкретної причинно-наслідкової траси.

## Correlation spine

```text
request / event / job
→ correlation id
→ tenant
→ Domain operation
→ policy / agent decision
→ persistence / external calls
→ result / error
```

## Logs

Structured log для consequential operation повинен, де доречно, містити:

- timestamp/severity;
- correlation/request/job id;
- organization/tenant reference;
- Domain/use case/action;
- outcome/error class;
- provider/integration reference без secret leakage.

## Metrics

Корисні категорії:

- request/use-case latency та error rate;
- event/outbox backlog;
- queue depth, attempts, dead-letter count;
- external provider latency/failure;
- Agent/LLM latency, usage/cost, schema failures;
- approval backlog;
- domain-specific operational KPIs.

## Tracing

Critical cross-boundary flows мають бути reconstructable навіть якщо повний distributed tracing не використовується. Correlation id повинен переживати queue/event/external hops, де це можливо.

## Incident triage

Порядок діагностики:

1. визначити user-visible symptom і tenant scope;
2. знайти correlation/request/job;
3. визначити Domain owner/use case;
4. перевірити permission/policy/approval result;
5. перевірити DB transaction/event/outbox;
6. перевірити queue/worker;
7. перевірити зовнішній provider;
8. зафіксувати root cause та recovery action.

## Alert quality

Alert повинен означати actionable condition. Якщо система надсилає 400 повідомлень про кожен retry, люди швидко винаходять найнадійніший monitoring tool: mute.

## Related

- [Audit & Diagnostics](../05-runtime/audit-and-diagnostics.md)
- [External Reliability](../07-api-integrations/external-reliability.md)
- [Deployment & Health](./deployment-and-health.md)
