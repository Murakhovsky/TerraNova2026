---
title: Kernel Overview
description: Відповідальності та межі COS Kernel.
status: active
updated: 2026-09-12
kind: architecture
---

# Kernel Overview

COS Kernel є generic execution layer. Він не знає бізнес-мову конкретного Domain, але забезпечує однакові правила виконання для всіх Domains.

Поточний executable Kernel contract: **`0.10.0`**.

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
| Extension | module-owned extension points without hardcoded Domain assembly |
| Module readiness | deployed/installed/schema/dependency diagnostics |
| Operations | worker lifecycle, health |
| Observability | metrics/logging contracts |
| LLM governance | routing, provider registry, fallback, budgets, usage accounting |

## Kernel does not own

Kernel не повинен визначати:

- Sales stages;
- Finance rules;
- Inventory semantics;
- конкретний CRM або LLM provider;
- SQL schema конкретного Domain;
- HTTP controllers;
- prompt зміст конкретного business Agent;
- Web navigation semantics конкретного Domain.

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

Domain реєструє через manifest/module contract свої contributions. Поточна модель включає, залежно від модуля:

- runtime module service;
- job handler services;
- API route contributor services;
- configuration provisioner services;
- generic `extension_services`;
- migration files;
- capabilities;
- Domain runtime contributions: events, actions, handlers, agents, context builders, rules, policies.

`DomainModuleRegistry` забезпечує business runtime ownership/routing. `ModuleExtensionRegistry` забезпечує generic extension points для delivery/configuration та інших cross-cutting surfaces.

Детальніше: [Extension Runtime](extension-runtime.md).

## Extension model

Kernel V0.9 прибрав необхідність hardcode-ити кожен Domain у shared bootstrap для нових extension surfaces.

```text
module.php
  ↓
ModuleContributions
  ↓
ModuleCatalog
  ↓
ModuleExtensionRegistry
  ↓
consumer resolves service
  ↓
request-time active-module guard where required
```

Kernel реєструє ownership як `module_id + extension_point + service_id`. Concrete service contract перевіряє рівень-споживач, наприклад Web layer для `web.navigation`.

## Governed LLM model

Kernel V0.10 робить structured LLM access керованим runtime-механізмом:

```text
StructuredLlmRequest
  ↓
Budget check
  ↓
Routing policy
  ↓
Provider registry
  ↓
Provider
  ↓
Usage record + metrics
```

Fallback дозволений тільки для retryable provider failures. Non-retryable error не маскується переходом на інший provider.

`organizationId`, `useCase` і `correlationId` проходять через request context, що дозволяє tenant budgets, use-case routing та traceability.

Детальніше: [LLM Governance](../06-ai-agents/llm-governance.md).

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
- execution має audit trail;
- module readiness не виконує migration side effects;
- LLM usage має organization/use-case telemetry і може бути обмежений monthly budget.

## Головний інваріант

> Domain визначає, **що означає дія**. Kernel визначає, **як вона безпечно проходить lifecycle**.
