---
title: Sales Lifecycle & Automation
description: Sales lifecycle from intake through pipeline execution, follow-up, outcome and governed automation.
status: active
updated: 2026-09-15
kind: domain
---

# Sales Lifecycle & Automation

## Canonical lifecycle

```text
Inbound demand
   ↓
Normalize / map source
   ↓
Lead create or update
   ↓
Qualification / assignment
   ↓
ClientCase / Deal
   ↓
Pipeline stage
   ↓
Activities / calls / messages
   ↓
Follow-up / next action
   ↓
Outcome
```

Public intake та CRM webhook є різними delivery paths, але повинні завершуватися canonical Sales state, а не двома паралельними моделями продажу.

## Direct execution

Людина або interface викликає application use case:

```text
Interface
  ↓
Use Case / DTO
  ↓
Sales validation + contracts
  ↓
Persistence mutation
  ↓
Sales Event
  ↓
Result
```

Controller, Telegram command чи worker не дублює pipeline rules і не виконує Sales SQL напряму.

## Automation loop

```text
Sales Event
   ↓
Rule context / Agent context
   ↓
Rule або Sales Agent
   ↓
ActionProposal
   ↓
Policy
   ├─ AUTO
   ├─ APPROVAL_REQUIRED
   └─ DENIED
   ↓
Queue / Action handler
   ↓
Sales application port
   ↓
Result Event + Audit
```

Agent створює proposal, але не має direct mutation authority.

## Operational rules

- duplicate external delivery обробляється idempotently;
- external side effects мають бути retry-safe;
- forbidden stage transition завершується domain/governance rejection;
- approval-required action не виконується до рішення;
- read side може мати окремі projections для workspace, history, funnel та management views.

## Execution reference

Exact use cases, commands та events не дублюються вручну:

- [Application Use Cases](../../12-reference/application-use-cases.md)
- [Commands](../../12-reference/commands.md)
- [Event Types](../../12-reference/event-types.md)
- [Execution Lifecycle](../../05-runtime/execution-lifecycle.md)
- [Policies & Approvals](../../05-runtime/policies-and-approvals.md)
