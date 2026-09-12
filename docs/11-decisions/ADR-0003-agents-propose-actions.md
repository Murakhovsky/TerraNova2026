---
title: ADR-0003 — Agents propose actions, they do not mutate state directly
status: accepted
updated: 2026-09-12
kind: decision
---

# Context

COS використовує LLM-based Agents для interpretation, prioritization і reasoning. Якщо Agent отримує прямий доступ до repositories, integration adapters або Action executors, probabilistic decision і mutation зливаються в одну неконтрольовану boundary.

Тоді неможливо надійно застосувати policy, human approval, idempotency та audit до AI-рішень.

# Decision

**Agent є decision component і може створювати лише validated ActionProposal.**

Канонічний path:

```text
Domain context
→ redaction
→ governed LLM inference
→ structured validation
→ ActionProposal
→ Action
→ Policy
→ optional Approval
→ Queue
→ Handler
→ Domain port
→ Result + Audit
```

Agent не може напряму:

- змінювати database state;
- викликати Action executor;
- відправляти повідомлення через concrete adapter;
- самостійно схвалювати власну дію;
- обходити Policy через tool/provider call.

# Rationale

Це відділяє probabilistic reasoning від deterministic authority.

Вигоди:

- одна governance model для AI і deterministic automation;
- deny/approval rules працюють незалежно від model/provider;
- structured output можна відхилити до mutation;
- кожна виконана дія має чітку causal trace;
- model replacement не змінює authorization semantics.

# Alternatives considered

## Direct tool calling with mutation authority

Відхилено як default: provider/tool semantics фактично ставали б authorization layer.

## Agent-specific permission system

Відхилено: дублював би Kernel Policy/Approval runtime.

## Заборонити Agents і використовувати тільки Rules

Відхилено: Rule недостатній для неоднозначного language/context reasoning.

# Consequences

Позитивні:

- сильний human/control boundary;
- testable proposals;
- provider-independent safety;
- повний audit execution path.

Вартість:

- більше lifecycle steps;
- потенційно вища latency;
- action vocabulary та schemas мають бути явними.

# Compatibility / Migration

Усі нові Agent capabilities повинні інтегруватися через `AgentResult`/proposal path. Якщо legacy AI code виконує mutation без Policy, він є migration target, а не допустимим альтернативним runtime.

# Verification

Перевіряються:

- sensitive context redaction;
- structured decision validation;
- unknown/forbidden proposal rejection;
- Policy/Approval behavior після proposal;
- відсутність direct Infrastructure/Action executor dependency в Agent code.

# Related

- `docs/06-ai-agents/agent-runtime.md`
- `docs/06-ai-agents/llm-governance.md`
- `docs/05-runtime/execution-lifecycle.md`
