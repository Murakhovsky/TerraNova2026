---
title: Current COS Scope
description: Фактичний функціональний склад гілки COS станом на 2026-09-12.
status: active
updated: 2026-09-12
kind: product
---

# Current COS Scope

Ця сторінка описує **реально присутні** bounded contexts, platform capabilities та delivery surfaces гілки `COS`. Вона не є roadmap.

## Поточна формула продукту

COS сьогодні — modular monolith з окремими Kernel, Domains, Interfaces, Infrastructure та Bootstrap.

```text
Interfaces
    ↓
Domain Application / Kernel runtime
    ↓
Domains
    ↓
Ports
    ↓
Infrastructure adapters
```

Kernel відповідає за механізми виконання. Domains відповідають за бізнес-смисл.

## Домени

### Sales

Найзріліший reference domain. Покриває inbound leads, people у Sales relationship, client cases/deals, pipeline, activities, follow-ups, property matches, Sales rules/agents/actions/policies, CRM synchronization, workspace та admin capabilities.

`Sales` має runtime module contribution, job handler contribution, capability catalogue, configuration provisioning і Web navigation extension.

### Diagnostic

Generic business-diagnostics domain. Покриває methodology packs, immutable pack versions, diagnostic sessions, evidence/facts/metrics, assessments, findings, hypotheses, recommendations, deterministic methodology engine, interview/report pipeline та structured LLM boundary.

Diagnostic не володіє Sales/Finance/HR моделями. Він працює через neutral target + methodology pack. Web navigation уже декларується як module-owned extension.

### Property

Real-estate domain. Поточний код включає property catalogue contracts, management, submissions, moderation, media storage contracts, presentations, analytics/funnel analytics, location references, Sales integration point та MySQL persistence adapters.

Module manifest і Web navigation extension існують, але повний runtime contribution/capability contract ще не на рівні Sales.

### Identity

Виділений bounded area для identity/application integration. На цьому етапі містить `Application` та `Infrastructure`; не має такого ж повного module/runtime contract, як Sales.

### Content

Виділений application/infrastructure area для content integration. Бізнес-логіка Content не повинна повертатися у Web controllers.

### Spatial

Виділений application/infrastructure area для spatial/3D capability. Shared technical adapters є також у root `Infrastructure/Spatial`.

## Kernel capabilities

Kernel має окремі механізми для:

- Event + Outbox;
- Rules;
- Agent runtime;
- structured LLM;
- Actions;
- Policies;
- Approvals;
- durable Queue;
- Audit;
- Transactions;
- Tenant context;
- Configuration;
- Module lifecycle;
- Module extension runtime;
- module readiness diagnostics;
- Operations та Observability;
- LLM routing, fallback, budgets, usage accounting і telemetry.

Виконувана версія Kernel contract: **`0.10.0`** (`Kernel\\Module\\KernelVersion`).

## Extension runtime

З V0.9 модулі можуть декларувати extension services через `module.php`, а Kernel збирає їх у `ModuleExtensionRegistry`.

Поточні extension points включають:

- `api.routes`;
- `tenant.configuration`;
- `web.navigation`.

Це прибирає hardcoded assembly конкретних Domains із shared runtime. Kernel знає extension point і service id, але не повинен знати Web/Sales/Property implementation details.

## LLM runtime governance

З V0.10 structured LLM calls проходять через governed runtime:

```text
StructuredLlmRequest
    ↓
organization budget check
    ↓
LlmRoutingPolicy
    ↓
LlmProviderRegistry
    ↓
provider call
    ↓
optional retryable fallback
    ↓
usage ledger + metrics
```

Request може переносити `organizationId`, `useCase` і `correlationId`. Explicit use-case routing policy має пріоритет над domain-level model hint.

Agent runtime і Diagnostic AI використовують цю shared governed LLM boundary, але залишаються різними за бізнес-семантикою.

## Delivery surfaces

`app/Interfaces` містить `Web`, `Api`, `Telegram`, `Cli`, `Shared`.

Це канали доставки, а не бізнес-домени. Controller не повинен стати власником business rule лише тому, що кнопку натиснули у браузері.

## Infrastructure

`app/Infrastructure` містить shared technical implementations: Framework, Identity, Integration, Llm, Media, Module, Observability, Platform, Security, Spatial.

Domain-owned persistence може жити всередині Domain. Shared platform adapters залишаються у root Infrastructure.

## Зрілість

- Sales — reference implementation modular/runtime contract.
- Diagnostic — зріла domain model + methodology runtime + governed structured LLM integration; module runtime contribution все ще не на рівні Sales.
- Property — має ports/use cases/adapters і module-owned navigation extension; runtime contribution неповний.
- Identity, Content, Spatial — уже відокремлені від delivery layer, але ще не мають повного Sales-рівня module contract.

Наявність директорії не дорівнює однаковій зрілості. Це правило рятує документацію від дуже красивої брехні.
