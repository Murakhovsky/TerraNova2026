---
title: Kernel Overview
description: Відповідальності та межі COS Kernel.
status: active
updated: 2026-09-11
kind: architecture
---

# Kernel Overview

COS Kernel є generic execution layer. Він не знає бізнес-мову конкретного Domain, але забезпечує однакові правила виконання для всіх Domains.

Детальний canonical document: [`docs/architecture/cos-kernel.md`](../architecture/cos-kernel.md).

## Kernel owns mechanisms

| Area | Responsibility |
| --- | --- |
| Event | immutable facts, metadata, publication, outbox |
| Rule | deterministic condition evaluation |
| Agent | structured LLM decision runtime |
| Action | proposal, lifecycle, controlled mutation |
| Policy | AUTO / APPROVAL_REQUIRED / DENIED |
| Approval | human decision lifecycle |
| Queue | durable jobs, retries, leases, dead letters |
| Audit | explanation and execution trail |
| Transaction | transaction contract without PDO coupling |
| Tenant | active organization context |
| Configuration | validation and provisioning |
| Module | installable Domain contract and registry |
| Operations | worker lifecycle, health |
| Observability | metrics/logging contracts |

## Kernel does not own

Kernel не повинен визначати:

- Sales stages;
- Finance rules;
- Inventory semantics;
- конкретний CRM provider;
- SQL schema конкретного Domain;
- HTTP controllers;
- prompt зміст конкретного business Agent.

## Dependency direction

```text
Kernel         -> PHP only
Domain         -> Kernel + same Domain
Infrastructure -> Kernel/Domain contracts
Interfaces     -> exposed Application/Kernel services
Bootstrap      -> all concrete layers
```

Architecture tests повинні ловити зворотні залежності до того, як вони перетворяться на «тимчасове рішення 2026 року», яке переживе три покоління розробників.

## Universal runtime loop

```text
Event
  ↓
Rule / Agent
  ↓
ActionProposal
  ↓
Action
  ↓
Policy
  ├─ DENIED
  ├─ APPROVAL_REQUIRED
  └─ AUTO
       ↓
Queue / Executor
       ↓
Handler
       ↓
Domain Port
       ↓
Infrastructure Adapter
       ↓
ExecutionResult
       ↓
Result Event + Audit + Metrics
```

## Domain module contract

Domain, який бере участь у runtime loop, реєструє через `DomainModuleInterface` свої contributions:

- owned event types;
- owned action types;
- action handlers;
- agent definitions;
- context builders;
- rule context;
- rule catalog;
- policy catalog.

`DomainModuleRegistry` забезпечує ownership і routing без domain-specific branching у Kernel.

## Persistence guarantees

Поточна архітектура базується на таких гарантіях:

- MySQL є source of truth;
- COS не є Event Sourcing;
- state + Event + Outbox зберігаються транзакційно;
- delivery at-least-once;
- side effects idempotent;
- tenant-scoped queries і mutations;
- LLM не виконує mutation напряму;
- Policy перевіряється перед Action execution;
- async work проходить через durable Queue;
- execution має audit trail.

## Головний інваріант

> Domain визначає, **що означає дія**. Kernel визначає, **як вона безпечно проходить lifecycle**.
