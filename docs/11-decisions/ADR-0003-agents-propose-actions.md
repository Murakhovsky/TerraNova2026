---
title: ADR-0003 — Agents пропонують Actions і не мутують state напряму
description: Рішення обмежити Agents формуванням ActionProposal без прямої мутації бізнес-стану.
status: accepted
updated: 2026-09-16
kind: decision
---

# Контекст

COS використовує LLM-based Agents для interpretation, prioritization і reasoning. Якщо Agent отримує прямий доступ до repositories, integration adapters або Action executors, probabilistic decision і mutation зливаються в одну неконтрольовану boundary.

Тоді неможливо надійно застосувати Policy, human approval, idempotency та Audit до AI-рішень.

# Рішення

**Agent є decision component і може створювати лише validated `ActionProposal`.**

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

# Обґрунтування

Це відділяє probabilistic reasoning від deterministic authority.

Переваги:

- одна governance model для AI і deterministic automation;
- deny/approval rules працюють незалежно від model/provider;
- structured output можна відхилити до mutation;
- кожна виконана дія має causal trace;
- заміна model/provider не змінює authorization semantics.

# Розглянуті альтернативи

## Direct tool calling із mutation authority

Відхилено як default: provider/tool semantics фактично ставали б authorization layer.

## Окрема система дозволів для Agent

Відхилено: дублював би Kernel Policy/Approval runtime.

## Лише Rules без Agents

Відхилено: Rule недостатній для неоднозначного language/context reasoning, де потрібна інтерпретація.

# Наслідки

Позитивні:

- сильна human/control boundary;
- testable proposals;
- provider-independent safety;
- повний audit execution path.

Вартість:

- більше lifecycle steps;
- потенційно вища latency;
- action vocabulary і schemas мають бути явними.

# Сумісність і міграція

Усі нові Agent capabilities інтегруються через `AgentResult`/proposal path. Legacy AI code, який виконує mutation без Policy, є migration target, а не допустимим паралельним runtime.

Tool permission є окремою authority boundary від здатності моделі сформувати tool call.

# Перевірка

Перевіряються:

- sensitive context redaction;
- structured decision validation;
- unknown/forbidden proposal rejection;
- Policy/Approval behavior після proposal;
- відсутність direct Infrastructure/Action executor dependency в Agent code;
- auditability від Agent invocation до execution result.

# Пов’язані матеріали

- `docs/06-ai-agents/agent-runtime.md`
- `docs/06-ai-agents/llm-governance.md`
- `docs/05-runtime/execution-lifecycle.md`
- `docs/05-runtime/policies-and-approvals.md`
