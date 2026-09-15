---
title: Reference Index
description: Карта exact/generated reference COS і правила вибору правильного джерела фактів.
status: active
updated: 2026-09-15
kind: reference
contract: reference-v1
---

# Reference Index

Reference section відповідає на питання **«що executable code або machine-readable documentation contracts декларують точно?»**.

Якщо потрібен сенс, починайте з Workflow/Domain/Architecture. Якщо потрібні точні names, versions, routes, capabilities, process ownership, Domain process coverage, capability debt або runtime mappings бізнес-процесу, приходьте сюди. Інакше prose дуже швидко стає базою даних, тільки гіршою.

Current Process Registry schema: **v5**.

Capability Debt Registry schema: **v1**.

Process Coverage Exemptions schema: **v1**.

## Authority model

```text
main executable metadata / code
        +
main structured platform/documentation contracts
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
| Canonical business processes, ownership, topology, capability coverage і runtime verification | [Business Process Registry](business-processes.md) | `resources/processes/*.json` + current-checkout runtime evidence |
| Які installable Domains мають canonical process model | [Domain Process Coverage](domain-process-coverage.md) | `app/Domains/*/module.php` + Process Registry + explicit exemptions |
| Open capability-model debt, severity і target capabilities | [Capability Debt Backlog](capability-debt.md) | `docs/.vitepress/capability-debt.json` + Process Registry gaps + module manifests |
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

## Як вибрати джерело

```text
Питання: «Навіщо?»
→ Concept / Product / ADR

Питання: «Як працює бізнес-процес?»
→ Workflow + ProcessDiagram

Питання: «Які installable Domains взагалі мають process model?»
→ Domain Process Coverage

Питання: «Хто відповідає за process steps і що mapped у runtime?»
→ Business Process Registry

Питання: «Які capability gaps уже визнані і як їх закривати?»
→ Capability Debt Backlog

Питання: «Хто володіє domain semantics?»
→ Domain / Architecture

Питання: «Як виконується?»
→ Runtime

Питання: «Яке точне ім'я/version/route/event?»
→ Generated Reference

Питання: «Де код?»
→ Code Map + generated source path
```

## Drift protection

CI генерує reference з поточного `main`, а потім перевіряє byte-for-byte sync перед VitePress build. Generated layer охоплює modules/capabilities, extension points, use cases, commands, events, routes, permissions, configuration ownership, database migrations, execution failures, architecture graph vocabulary, Business Process Registry, Domain Process Coverage та Capability Debt Backlog.

Process Registry schema `v5` додає contract-guarded cross-domain steps поверх backward-compatible v4 same-domain definitions і перевіряє topology, ownership, step Domain, canonical capability або explicit capability gap та runtime evidence. Capability Debt Registry schema `v1` вимагає рівно один debt item для кожного process capability gap і відхиляє stale debt, якщо target capability уже з'явилась у module authority. Domain Process Coverage gate вимагає process model або explicit exemption для кожного installable Domain і не плутає supporting directories з module manifests.

Narrative `Current Scope`, Domain overviews і цей Reference Index мають окремі drift checks проти executable/structured authorities. Зміна contract без синхронізації knowledge layer має ставати build defect, а не сюрпризом через два місяці.
