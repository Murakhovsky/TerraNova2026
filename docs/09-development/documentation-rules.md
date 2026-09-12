---
title: Documentation Rules
description: Правила підтримки COS documentation як живої частини codebase.
status: active
updated: 2026-09-12
kind: development
---

# Documentation Rules

COS documentation є частиною codebase, а не окремим wiki, який згадують раз на квартал під час ритуального прибирання.

## Source of truth

```text
Executable code + tests    фактична поведінка
/docs                      explanation, workflows, architecture, reference
/docs/11-decisions         rationale та history довгоживучих рішень
```

Generated documentation site лише рендерить `/docs` і не створює другу content source.

## Коли documentation update обов'язковий

Оновлюйте docs у тому самому change, якщо змінюється:

- Domain ownership або boundary;
- Kernel mechanism/lifecycle;
- module manifest, lifecycle або extension point;
- public/internal API contract;
- Event/Action/Policy semantics;
- Agent/LLM governance;
- persistence ownership;
- deployment/migration workflow;
- суттєвий user/operator workflow.

## Page types

### Concept

Пояснює mental model і терміни. Не дублює class reference.

### Workflow

Показує business goal, actors, input, lifecycle, COS implementation, runtime, UI і code map.

### Architecture

Пояснює boundaries, dependency direction, invariants і system flow.

### Domain

Пояснює bounded context: vocabulary, ownership, use cases, automation, ports, persistence, interfaces.

### How-to

Покрокова інструкція для developer/operator.

### Reference

Точні contracts, statuses, events, permissions, configuration. Reference, яке можна надійно отримати з executable metadata, у перспективі генерується.

### ADR

Фіксує Context → Decision → Rationale → Alternatives → Consequences → Verification.

## AS-IS vs TARGET

Будь-яка сторінка повинна чітко розрізняти:

- **AS-IS** — підтверджено code/tests;
- **TARGET** — запланований direction, ще не повністю executable.

TARGET не описується граматикою «система робить», якщо система цього ще не робить.

## Frontmatter

Canonical page мінімально має:

```yaml
---
title: Page Title
description: Short description.
status: active
updated: YYYY-MM-DD
kind: architecture
---
```

Recommended `kind`:

```text
concept
product
workflow
architecture
domain
runtime
agent
integration
ui
development
operations
decision
reference
```

## Navigation

Numeric canonical sections автоматично входять у website sidebar. Не підтримуйте паралельний ручний список кожного нового файла в UI config.

Для великих вкладених розділів використовуйте directory hierarchy, а не filename на 90 символів.

## Links

Внутрішні Markdown links мають бути relative, якщо сторінки рухаються разом, або root-relative лише коли це свідомий stable documentation route.

Не посилайтеся на generated `public/docs` files з source Markdown.

## Code references

Коли документ пояснює implementation, додавайте `Code map` з canonical paths, але не копіюйте великі фрагменти PHP у docs. Код змінюється швидше за prose.

## Generated reference

TARGET:

```text
module manifests ─┐
routes             ├─> generated reference
Events/Actions     ┤
permissions        ┤
DB schema          ┘
```

Manual narrative лишається для meaning/rationale. Machine-readable facts не повинні вручну підтримуватись у п'яти таблицях.

## CI

`npm run docs:build` є базовою перевіркою documentation site. CI запускає його при змінах docs/config.

У перспективі CI також має перевіряти:

- internal links;
- duplicate page identifiers;
- stale code paths;
- frontmatter schema;
- generated reference drift.

## Ownership

Автор code change не зобов'язаний написати енциклопедію. Він зобов'язаний оновити той documentation contract, який змінився.

Правило просте: якщо без diff неможливо зрозуміти нову поведінку з документації, change не завершений.
