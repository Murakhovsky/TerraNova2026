---
title: Documentation Rules
description: Правила docs-as-code, source of truth і синхронізації документації COS з executable architecture.
status: active
updated: 2026-09-12
kind: development
---

# Documentation Rules

`/docs` є канонічною knowledge layer COS, але executable truth усе одно визначають code + tests.

Мета документації — не дублювати кожен клас вручну, а пояснювати:

- product semantics;
- workflows;
- ownership;
- architecture boundaries;
- runtime lifecycle;
- operational rules;
- rationale великих рішень;
- navigation від бізнес-поняття до коду.

## Source of truth hierarchy

```text
Executable behavior → code + tests
Architecture rationale → ADR
Current architecture/workflow explanation → /docs
Generated reference → code/schema/manifests as source
Historical intent → legacy plans / old ADR
```

Якщо current docs суперечать executable code, це documentation defect.

## AS-IS і TARGET

Кожна суттєва сторінка повинна чітко відрізняти:

- `AS-IS` — підтверджено поточним code path;
- `TARGET` — бажаний стан, який ще не завершений.

Не можна описувати TARGET як production capability.

## Page types

Основні `kind`:

- `concept` — mental model / термін;
- `product` — product scope/capability;
- `workflow` — наскрізний business flow;
- `architecture` — system boundaries/mechanisms;
- `domain` — конкретний bounded context;
- `agent` — Agent/LLM behavior;
- `development` — how-to для розробника;
- `operations` — deployment/readiness/monitoring;
- `decision` — ADR;
- `reference` — glossary/catalogue/generated-like reference;
- `index` — navigation page.

## Status values

Для current docs:

- `active` — чинна сторінка;
- `draft` — неповна/неперевірена;
- `deprecated` — більше не є current guidance;
- `historical` — лишається для history/migration context.

Для ADR використовуються окремі decision statuses з `11-decisions/README.md`.

## Коли документація має оновлюватися разом із кодом

Docs review обов'язковий, якщо commit змінює:

1. `KernelVersion` або Kernel public contract;
2. module manifest/contributions/extensions;
3. Domain ownership або canonical workflow;
4. Action/Policy/Approval lifecycle;
5. Agent/LLM governance behavior;
6. persistence guarantees або migration semantics;
7. tenant isolation/configuration model;
8. public API/delivery surface;
9. operational readiness/status model;
10. architecture dependency direction.

Не кожен refactor вимагає docs change. Зміна поведінки або mental model — вимагає.

## Не дублювати те, що можна генерувати

Reference data, яке має executable source, бажано генерувати або перевіряти автоматично:

- routes;
- module manifests;
- capabilities;
- permissions;
- events/action catalogues;
- configuration keys;
- DB schema/migrations;
- API schemas.

Curated docs повинні пояснювати **сенс і зв'язки**, а не вручну підтримувати 300-рядкову копію enum, яка гарантовано застаріє в п'ятницю ввечері.

## Code links

Architecture/workflow docs повинні мати `Code map` для ключових implementation paths, але не прив'язувати пояснення до випадкових line numbers.

Хороший рівень:

```text
app/Kernel/Llm/GovernedStructuredLlmClient.php
app/Domains/Sales/Application/UseCase/
```

Поганий рівень:

```text
див. рядок 183 класу X
```

Line numbers нестабільні й не є architecture contract.

## Workflow page template

```text
Business goal
Actors
Trigger / Input
Canonical state
Main flow
Decision points
Failure/retry behavior
Runtime mapping
UI/API surfaces
Invariants
Code map
AS-IS gaps / TARGET
```

Workflow має мостити:

```text
business concept
→ Domain
→ use case/event
→ runtime
→ infrastructure
→ UI/API
```

## Architecture page template

```text
Problem / responsibility
Boundary
Components
Flow
Ownership
Invariants
Failure model
Operational implications
Code map
Related decisions/docs
```

## Domain page template

```text
Purpose
Owned vocabulary
Entities/value objects
Use cases
Business events
Automation
Ports
Persistence
Read models
Module contributions
Cross-domain integrations
Invariants
Maturity / gaps
Code map
```

## ADR rule

Коли змінюється довгострокове architecture рішення, не переписуємо історію так, ніби попереднього рішення не існувало.

Створюємо/оновлюємо ADR, а current docs показують актуальний стан.

## Review checklist

Перед merge docs change:

- чи терміни відповідають Glossary;
- чи версії відповідають code;
- чи AS-IS не змішаний з TARGET;
- чи Domain/Kernel ownership описаний правильно;
- чи немає provider-specific detail у Domain docs;
- чи links/paths існують;
- чи новий page доданий у navigation/index, якщо він важливий;
- чи зміна не дублює generated reference вручну.

## Головне правило

> Documentation повинна скорочувати шлях від «що це за бізнес-функція?» до «де і чому вона так реалізована?», а не просто збільшувати кількість Markdown-файлів у репозиторії.
