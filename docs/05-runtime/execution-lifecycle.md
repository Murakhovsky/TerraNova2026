---
title: Execution Lifecycle
description: Наскрізний runtime від бізнес-транзакції до результату.
status: active
updated: 2026-09-11
kind: runtime
---

# Execution Lifecycle

Ця сторінка описує наскрізний execution path COS.

## Phase 1. Business state change

Ініціатором може бути Web/API/CLI/Telegram/webhook/worker або інший internal process.

Interface викликає Domain Application use case. Use case:

1. перевіряє domain invariants;
2. змінює business state;
3. створює Domain Event;
4. зберігає state + Event + Outbox в одній transaction boundary.

```text
Interface
  ↓
Application Use Case
  ↓
Domain Model
  ↓
Transaction(state + event + outbox)
```

## Phase 2. Event delivery

Після commit durable consumer читає Outbox.

Важлива властивість: automation не є частиною початкового DB request. Це ізолює business transaction від LLM latency, CRM outage або тимчасової помилки integration provider.

Delivery — at-least-once. Отже consumer та всі downstream mutations повинні бути idempotent.

## Phase 3. Context construction

Event сам по собі містить факт, але для рішення часто потрібен додатковий context.

Domain-owned context provider збирає tenant-scoped дані для:

- Rule;
- Agent;
- Policy;
- Action handler, якщо це частина його contract.

Context не повинен будуватися довільним SQL всередині Agent.

## Phase 4. Decision

### Rule path

Rule оцінює deterministic condition.

```text
Event + RuleContext → true/false / deterministic result
```

### Agent path

AgentRuntime:

1. знаходить AgentDefinition;
2. отримує context через routed context builder;
3. redacts sensitive data;
4. викликає LLM contract;
5. validates structured decision;
6. повертає AgentResult / ActionProposal.

Agent не має доступу до ActionExecutor або infrastructure adapters.

## Phase 5. Action materialization

ActionProposal перетворюється на Action з типом, payload, tenant context, idempotency identity та lifecycle status.

Action є одиницею виконання, а не просто довільним масивом JSON.

## Phase 6. Policy gate

Перед mutation виконується Policy evaluation.

```text
AUTO              → execute/schedule
APPROVAL_REQUIRED → create Approval
DENIED            → stop + audit
```

Відсутність matching policy не означає “мабуть можна”. Default deny.

## Phase 7. Human approval

Для sensitive actions Approval фіксує людське рішення. Approval не обходить Action lifecycle, а повертає схвалену Action в нормальний execution path.

Відхилена Action не виконується.

## Phase 8. Durable execution

LLM work, mutations та integration calls, для яких потрібна reliability, виконуються через Queue.

Queue забезпечує:

- durable persistence;
- lease/claim;
- retry;
- attempt count;
- delayed availability;
- dead-letter behavior;
- worker recovery.

## Phase 9. Handler routing

ActionExecutor визначає handler через registered Domain module contributions.

```text
Action type
   ↓
DomainModuleRegistry
   ↓
Action handler
   ↓
Outbound port
   ↓
Infrastructure adapter
```

У Kernel немає `if sales`, `if finance` або provider-specific logic.

## Phase 10. Result

Handler повертає `ExecutionResult`.

Kernel завершує Action lifecycle і створює generic technical result Event:

- `cos.action.completed`;
- `cos.action.failed`.

Якщо execution змінив business state, відповідний Domain створює власний business Event.

## Phase 11. Audit and metrics

Кожен важливий decision/execution point має бути корельований через IDs і tenant context.

На виході можна відновити:

```text
Event
 → rule/agent decision
 → proposal
 → policy
 → approval
 → job
 → action
 → handler
 → result
```

## Failure boundaries

| Failure | Expected behavior |
| --- | --- |
| Domain transaction fails | no committed state/event/outbox |
| Consumer fails | event remains retryable |
| LLM fails | job retry/fail; no mutation |
| structured output invalid | reject decision; no action |
| Policy denies | no execution |
| Approval rejected | no execution |
| External CRM unavailable | retry/idempotent integration path |
| Handler fails | failed result + audit + retry strategy |

## Runtime invariant

> Жодна автоматизація або Agent не має створювати side effect шляхом, який неможливо пояснити через Event → Decision → Action → Policy → Execution → Result.
