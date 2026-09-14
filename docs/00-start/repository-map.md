---
title: Repository Map
description: Карта executable COS codebase та окремого documentation surface у main.
status: active
updated: 2026-09-14
kind: reference
---

# Repository Map

Ця сторінка пояснює, **де шукати сенс і реалізацію**, а не намагається переписати `tree` команду красивішими літерами.

## Branch contract

У документації та executable code різні canonical branches:

```text
COS
├── app/            executable product
├── tests/          executable verification
├── docs generators / generated reference authority
└── ...

main
└── docs/           canonical narrative documentation + WEB publication
```

Тому `main` не є «legacy application» у документаційному mental model. Він є publication branch для knowledge layer. Product AS-IS читаємо з `COS`.

## Верхній рівень executable architecture

```text
app/
├── Kernel/
├── Domains/
├── Infrastructure/
├── Interfaces/
├── Bootstrap/
└── config/

tests/
bin/
```

Думати про це варто так:

```text
Interfaces     приймають зовнішню взаємодію
Application    оркеструє use case
Domain         визначає business meaning
Kernel         дає execution mechanisms
Infrastructure реалізує technical adapters
Bootstrap      збирає все разом
```

## `app/Kernel`

Kernel містить generic platform/runtime mechanisms.

Основні families:

```text
Action
Agent
Approval
Audit
Configuration
Database / Transaction support
Event
Llm
Module
Observability
Operations
Policy
Queue
Resilience
Rule
Tenant
Transaction
```

Головне правило: Kernel не повинен залежати від конкретної Sales/Property/Diagnostic business semantics.

Якщо в Kernel з'являється щось на кшталт `if ($domain === 'sales')`, десь архітектура вже тихенько плаче.

## `app/Domains`

Поточний `COS` фізично містить:

```text
Content/
Diagnostic/
Identity/
Property/
Sales/
Spatial/
```

Але це **не список однаково зрілих installable modules**.

### Installable modules

Наявний `module.php`:

```text
Sales/
Diagnostic/
Property/
```

Їхні exact versions і contributions генеруються у [Module and Capability Reference](../12-reference/module-capabilities.md).

### Supporting bounded areas

```text
Identity/
Content/
Spatial/
```

Вони вже відокремлені від delivery layer, але в поточному `COS` не мають того самого installable module contract.

## Sales structure

Sales є найкращим reference для повного Domain layout:

```text
Domains/Sales/
├── Domain/
├── Model/
├── Application/
│   ├── Contract/
│   ├── DTO/
│   ├── Service/
│   ├── Support/
│   └── UseCase/
├── Automation/
│   ├── Event/
│   ├── Rule/
│   ├── Agent/
│   ├── Action/
│   ├── Job/
│   ├── Integration/
│   └── Policy/
├── Infrastructure/
├── Bootstrap/
├── module.php
└── README.md
```

Не кожен Domain зобов'язаний мати всі ці директорії. Structure має слідувати реальній відповідальності, а не корпоративному культу порожніх папок.

## Diagnostic structure

Diagnostic організований навколо своєї methodology/evidence problem space:

```text
Domains/Diagnostic/
├── Model/
├── Methodology/
├── Application/
├── Automation/
├── AI/
├── Interview/
├── Evaluation/
├── Report/
├── Infrastructure/
└── module.php
```

Це хороший приклад того, чому Domains не повинні механічно копіювати Sales directory tree.

## Property structure

У поточному `COS` Property компактніший:

```text
Domains/Property/
├── Model/
├── Application/
│   ├── Contract/
│   ├── Service/
│   └── UseCase/
├── Infrastructure/
│   ├── Persistence/
│   ├── Presentation/
│   └── ReadModel/
└── module.php
```

Property already has a rich application contract surface, але його installable/runtime maturity ще нижча за Sales.

## `app/Infrastructure`

Root Infrastructure містить **shared technical implementations**, наприклад provider clients, LLM transport, framework adapters, security, observability, media, integration plumbing.

Правило залежностей:

```text
Infrastructure
    ↓ may implement
Domain / Kernel contracts
```

але не навпаки.

Domain-owned persistence також може жити всередині самого Domain, якщо це краще відображає ownership.

## `app/Interfaces`

Delivery layer:

```text
Interfaces/
├── Web/
├── Api/
├── Telegram/
├── Cli/
└── Shared/
```

Типовий flow:

```text
HTTP / Telegram / CLI input
    ↓
Interface adapter
    ↓
Application use case / runtime
    ↓
Domain
```

Controller не є місцем для domain transitions, SQL, provider selection або «тимчасової» business logic, яка чомусь переживає три роки.

## `app/Bootstrap`

Bootstrap є composition root.

Тут дозволено бачити concrete implementations і зв'язувати:

```text
Domain contract
    ↓
Infrastructure adapter

Module contribution
    ↓
Kernel registry

Interface
    ↓
Application/runtime service
```

Business class не повинен сам лазити у global DI container в пошуках того, хто сьогодні реалізує його dependency.

## Де лежить documentation

Canonical human-readable content:

```text
main:/docs/
```

Основні sections:

```text
00-start            onboarding / mental model
01-product          current product scope
02-workflows        business workflows
03-architecture     system boundaries
04-domains          domain documentation
05-runtime          execution mechanisms
06-ai-agents        AI / Agent model
07-api-integrations integration surfaces
08-ui               interfaces/documentation WEB
09-development      developer rules/how-to
10-operations       build/deployment/operations
11-decisions        ADR
12-reference        generated/exact reference
```

`public/docs` є build artifact, не source of truth.

## Generated reference

Якщо потрібно дізнатися **точний executable список**, не шукайте його по narrative pages.

Починайте з:

- [Module and Capability Reference](../12-reference/module-capabilities.md)
- [Module Extension Points](../12-reference/extension-points.md)
- [Application Use Cases](../12-reference/application-use-cases.md)
- [Event Types](../12-reference/event-types.md)
- [Command DTO Reference](../12-reference/commands.md)
- [Module Routes](../12-reference/module-routes.md)
- [Permissions & Capabilities](../12-reference/permissions-capabilities.md)

## Як знайти код за бізнес-задачею

Не починайте з назви класу. Починайте з ownership.

```text
Business question
    ↓
Workflow
    ↓
Owning Domain
    ↓
Application Use Case / Automation
    ↓
Contract / Event
    ↓
Infrastructure Adapter
    ↓
Interface
```

### Приклад: Sales follow-up

```text
Sales workflow
→ Domains/Sales/Application або Automation
→ Sales Event / Action / Policy
→ Kernel execution mechanism
→ outbound Contract
→ Infrastructure adapter
```

### Приклад: Property submission

```text
Property workflow
→ Domains/Property/Application/UseCase
→ Property Application contracts
→ Infrastructure/Persistence
→ delivery Interface
```

### Приклад: Diagnostic recommendation

```text
Diagnostic workflow
→ Methodology / Session / Evidence
→ Evaluation
→ AI boundary when needed
→ Result / Recommendation
```

## Три правила навігації

1. **Meaning before implementation.** Спочатку Domain/Workflow, потім class.
2. **Generated facts before copied tables.** Exact versions/events/routes дивимося у Reference.
3. **COS before main for executable truth.** `main:/docs` пояснює код, але не підміняє його.

Ці три правила економлять дивовижну кількість часу, який інакше йде на вивчення того, чому файл із назвою `ManagerService` керує зовсім не тим, чим здається.
