---
title: Reference Index
description: Карта exact/generated reference COS і правила вибору правильного джерела фактів.
status: active
updated: 2026-09-15
kind: reference
---

# Reference Index

Reference section відповідає на питання **«що executable code декларує точно?»**.

Якщо потрібен сенс, починайте з Workflow/Domain/Architecture. Якщо потрібні точні names, versions, routes або capabilities, приходьте сюди. Інакше prose дуже швидко стає базою даних, тільки гіршою.

## Authority model

```text
main executable metadata / code
        ↓
COS generators
        ↓
generated Markdown
        ↓ byte-for-byte sync
main:/docs/12-reference
        ↓
VitePress WEB
```

Generated files не редагуються вручну у `main`.

## Generated reference

| Потрібно дізнатися | Відкрити | Source authority |
| --- | --- | --- |
| Modules, versions, schema versions, capabilities, migrations | [Module & Capability Reference](module-capabilities.md) | `app/Domains/*/module.php`, KernelVersion |
| Module extension points і contributions | [Module Extension Points](extension-points.md) | module manifests + Kernel extension registry |
| Application entry points / use cases | [Application Use Cases](application-use-cases.md) | Domain Application/UseCase structure |
| Explicit Command DTO contracts | [Command DTO Reference](commands.md) | `Application/DTO/*Command.php` |
| Canonical runtime event types | [Event Types](event-types.md) | event catalogues / `TYPE` constants |
| Module-owned routes | [Module Routes](module-routes.md) | route contributors / routing metadata |
| Permissions / capability authority | [Permissions & Capabilities](permissions-capabilities.md) | executable permission/capability declarations |
| Module configuration ownership | [Configuration Reference](configuration.md) | module manifests / configuration provisioners |
| Database migration ownership and table touches | [Database Reference](database.md) | manifest `migration_files` + migration SQL |
| Canonical execution failure taxonomy | [Errors & Failures Reference](errors-and-failures.md) | `ExecutionFailureKind` + classified failures |
| Architecture graph vocabulary and projections | [Architecture Graph Reference](architecture-graph.md) | Visualization vocabulary / projection registry |

## Narrative reference

Не все варто генерувати. Meaning та cross-cutting explanation лишаються human-maintained.

### [Glossary](glossary.md)

Canonical vocabulary та терміни COS.

### [Kernel Components](kernel-components.md)

Навігаційний reference по основних Kernel mechanisms і їхній ролі.

## Що не шукати тут

Reference section не повинна пояснювати:

- навіщо існує Domain;
- як проходить business workflow;
- чому обрана конкретна architecture;
- які alternatives були відхилені;
- як користувач працює з UI.

Для цього є Domain, Workflow, Architecture, ADR та UI sections.

## Як вибрати джерело

```text
Питання: «Навіщо?»
→ Concept / Product / ADR

Питання: «Як працює бізнес-процес?»
→ Workflow

Питання: «Хто цим володіє?»
→ Domain / Architecture

Питання: «Як виконується?»
→ Runtime

Питання: «Яке точне ім'я/version/route/event?»
→ Generated Reference

Питання: «Де код?»
→ Code Map + generated source path
```

## Drift protection

CI генерує reference з поточного `main`, а потім перевіряє byte-for-byte sync перед VitePress build. Після DOC V0.9.2 generated layer охоплює modules/capabilities, extension points, use cases, commands, events, routes, permissions, configuration ownership, database migrations, execution failures та architecture graph vocabulary.

Narrative `Current Scope` і Domain overviews мають окремі version-drift checks проти module manifests. Зміна executable contract без синхронізації knowledge layer має ставати build defect, а не сюрпризом через два місяці.
