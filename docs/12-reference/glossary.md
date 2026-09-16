---
title: Глосарій COS
description: Канонічні терміни COS і короткі правила їх використання.
status: active
updated: 2026-09-16
kind: reference
contract: reference-v1
---

# Глосарій COS

Цей глосарій фіксує значення слів у контексті COS. Якщо одна й та сама назва означає п’ять різних речей, архітектура дуже швидко починає розмовляти сама з собою.

## Бізнес і Domain

### Domain
Bounded business context, який володіє власною мовою, state, invariants, use cases і business Events. Приклади: Sales, Diagnostic, Property.

### Entity
Business object з identity та lifecycle усередині Domain.

### Value Object
Об’єкт, значення якого визначається даними, а не окремою identity.

### Invariant
Правило, яке Domain має зберігати незалежно від Interface або provider.

### Use Case
Application-level операція, яка координує Domain behavior для конкретної бізнес-мети.

### Business Event / Domain Event
Immutable факт про те, що вже сталося в Domain. Event не є наказом виконати наступну дію.

### Capability
Канонічно задекларована здатність Domain/module, яку можуть використовувати platform, UI, authorization, Process Registry та introspection.

### Capability Gap
Явна прогалина, коли process step існує, але owning Domain ще не має достатньо точної semantic capability у manifest. Gap не означає автоматично, що runtime не реалізований.

## Process

### Process Registry
Платформний registry канонічних business-process definitions у `resources/processes/*.json`.

### Process Definition
Machine-readable модель процесу: id, root Domain, state, actors, steps, edges, ownership, capabilities/gaps, criticality та runtime mappings.

### Process Step
Окремий meaningful крок процесу з primary owner, Domain, capability або explicit gap та optional runtime evidence.

### Process State
Authored business truth: `as-is` або `to-be`. Не плутати з derived verification.

### Derived Verification
Рівень підтвердження process mappings поточним checkout: `documented`, `source-verified`, `runtime-verified`. Не задається автором вручну.

## Runtime

### Kernel
Generic execution mechanisms COS. Kernel знає Action, Policy, Queue, Event, Agent, Module, Process structure тощо, але не знає, що означає «кваліфікований лід».

### Runtime
Виконуваний lifecycle механізму: від input/event через decision та authority до execution, result і audit.

### Rule
Deterministic decision logic. Однаковий валідний context має давати однаковий результат.

### Agent
LLM-based decision component, який працює в bounded context contract і повертає structured result/proposal. Не має прямої mutation authority.

### ActionProposal
Структурована пропозиція виконати Action. Proposal ще не означає permission або execution.

### Action
Контрольована одиниця mutation/side effect із власним lifecycle.

### Policy
Механізм, який вирішує, чи Action може виконуватись автоматично, потребує Approval або заборонена.

### Approval
Human decision gate для Action, яку Policy не дозволяє виконати автоматично.

### Handler
Компонент, який виконує конкретний Action/Job через дозволені Domain ports/services.

### Job
Durable asynchronous work item для Queue runtime.

### Queue
Механізм durable execution із retry, lease/dead-letter semantics там, де вони потрібні.

### ExecutionResult
Структурований результат виконання Action/Job.

### Audit
Trace того, що сталося, хто або що прийняло рішення, яка Policy спрацювала і який був результат.

## Delivery і reliability

### Outbox
Durable record business Event/delivery intent, записаний узгоджено з business state transaction.

### At-least-once delivery
Delivery model, у якому повідомлення може бути доставлене повторно. Тому side effects мають бути idempotent.

### Idempotency
Властивість, за якої повторне виконання того самого logical request не створює небажаного дубльованого effect.

### Inbox
Durable inbound processing boundary для external/asynchronous messages, який допомагає з idempotency та retries.

### Correlation ID
Identifier для зв’язування частин одного logical flow між runtime, integrations, LLM calls, audit і metrics.

## Архітектурні межі

### Port
Contract, через який Domain/Application звертається до зовнішньої capability без знання concrete implementation.

### Adapter
Concrete implementation Port для MySQL, CRM, LLM, messaging або іншої external system.

### Cross-domain Contract
Явно задекларована межа між Domains у `cross_domain_contracts`, яка фіксує contract FQCN, роль `requires`/`provides`, counterpart, kind і purpose. Не створює shared ownership.

### Interface
Delivery surface, наприклад Web, API, Telegram, CLI. Interface приймає external input і викликає application/runtime capabilities.

### Infrastructure
Concrete technical implementations: persistence, HTTP providers, external integrations, framework adapters, telemetry.

### Bootstrap / Composition Root
Місце, де concrete dependencies збираються разом. Це один із небагатьох рівнів, якому дозволено знати різні layers одночасно.

### Read Model
Projection/query contract для читання даних під конкретний UI/API use case без перенесення write-domain logic у controller.

## Modules

### Module
Installable/runtime-manageable COS unit із manifest, version compatibility та contributions.

### Module Manifest
Declarative metadata module: id, version, schema version, Kernel constraint, dependencies, capabilities та contributions.

### Module Contribution
Runtime/service contribution, яку module реєструє у shared platform mechanisms.

### Extension Point
Named surface, куди modules declaratively підключають services без hardcoded Domain assembly. Наприклад `web.navigation`.

### Deployed Module
Module code/manifest присутній у поточному deployment.

### Installed Module
Module має persisted installation state у platform.

### Enabled Module
Organization configuration просить активувати module.

### Active Module
Module реально доступний після врахування installation, compatibility, dependencies/configuration та resolver rules.

### Ready Module
Operational diagnostic не бачить installation/version/schema/dependency blockers і module enabled.

## Tenant model

### Organization / Tenant
Business isolation scope COS. Дані, configuration, module activation, budgets та багато runtime operations прив’язуються до organization.

### Tenant-scoped
Operation/query, яка явно обмежена поточною organization і не може випадково прочитати або змінити state іншого tenant.

## LLM і AI

### Structured LLM
Provider-neutral LLM call із визначеним request/response contract, а не довільний chat transcript.

### LLM Provider
Concrete inference provider behind Infrastructure adapter.

### LLM Route
Pair `provider + model`, яку routing policy може вибрати для request.

### Use Case Routing
LLM policy, яка вибирає ordered routes для конкретного `useCase`.

### Fallback
Перехід на наступний configured LLM route після retryable provider failure.

### LLM Governance
Shared runtime для routing, provider registry, fallback, organization budgets, usage accounting та metrics.

### Proposal-only Agent
Agent, який може сформувати рішення/ActionProposal, але не має права напряму виконувати mutation.

## Терміни документації

### AS-IS
Функціональність або architecture, підтверджена поточним `main` code/tests/manifests/structured contracts.

### TARGET
Бажаний напрямок або правило, яке ще не реалізоване повністю.

### Source of Truth
Авторитетне джерело для конкретного типу факту. Для executable behavior це code/tests/manifests; для process topology це Process Registry; для rationale це ADR; для пояснення поточної architecture/workflow це current docs.

### ADR
Architecture Decision Record: документ, який фіксує контекст, рішення, обґрунтування, альтернативи, наслідки та перевірку.

## Коротка ментальна формула

```text
FACT
→ DECISION
→ PROPOSAL
→ PERMISSION
→ EXECUTION
→ RESULT
→ EXPLANATION
```
