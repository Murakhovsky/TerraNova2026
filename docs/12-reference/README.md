---
title: Індекс технічного довідника
description: Карта точного/generated reference COS і правила вибору правильного джерела фактів.
status: active
updated: 2026-09-16
kind: reference
contract: reference-v1
---

# Індекс технічного довідника

Цей розділ відповідає на питання: **що executable code або machine-readable documentation contracts декларують точно?**

Якщо потрібен сенс, починайте з Workflow, Domain або Architecture. Якщо потрібні точні names, versions, routes, capabilities, process ownership, cross-domain boundaries, Domain process coverage, capability debt чи runtime mappings, дивіться Reference. Інакше prose дуже швидко стає базою даних, тільки гіршою.

Поточна максимальна Process Registry schema: **v5**. Same-domain definitions також підтримують schema **v4**.

Capability Debt Registry schema: **v1**.

Process Coverage Exemptions schema: **v1**.

## Модель авторитетності

```text
main executable metadata / code
        +
main structured documentation contracts
        ↓
COS generators
        ↓
generated Markdown
        ↓ byte-for-byte sync
main:/docs/12-reference
        ↓
VitePress Web
```

Generated files у `docs/12-reference` не редагуються вручну. Зміна їхньої структури або мови робиться в generator/structured authority, після чого generated output комітиться разом зі зміною.

## Згенерований довідник

| Потрібно дізнатися | Відкрити | Авторитетне джерело |
| --- | --- | --- |
| Канонічні бізнес-процеси, ownership, topology, capability coverage і runtime verification | [Business Process Registry](business-processes.md) | `resources/processes/*.json` + current-checkout runtime evidence |
| Які процеси перетинають Domain boundaries і через які contracts/capabilities | [Cross-Domain Process Topology](cross-domain-process-topology.md) | Process Registry v5 + current-checkout contract evidence |
| Які installable Domains мають canonical process model | [Domain Process Coverage](domain-process-coverage.md) | `app/Domains/*/module.php` + Process Registry + explicit exemptions |
| Визнаний capability-model debt, severity і target capabilities | [Capability Debt Backlog](capability-debt.md) | `docs/.vitepress/capability-debt.json` + Process Registry gaps + manifests |
| Modules, versions, schema versions, capabilities, migrations | [Module & Capability Reference](module-capabilities.md) | `app/Domains/*/module.php` + `KernelVersion` |
| Module extension points і contributions | [Module Extension Points](extension-points.md) | module manifests + Kernel extension registry |
| Application entry points / use cases | [Application Use Cases](application-use-cases.md) | Domain `Application/UseCase` structure |
| Explicit Command DTO contracts | [Command DTO Reference](commands.md) | `Application/DTO/*Command.php` |
| Canonical runtime Event types | [Event Types](event-types.md) | event catalogues / `TYPE` constants |
| Module-owned routes | [Module Routes](module-routes.md) | route contributors / routing metadata |
| Permissions / capability authority | [Permissions & Capabilities](permissions-capabilities.md) | executable permission/capability declarations |
| Module configuration ownership | [Configuration Reference](configuration.md) | manifests / configuration provisioners |
| Database migration ownership і table touches | [Database Reference](database.md) | manifest `migration_files` + migration SQL |
| Canonical execution failure taxonomy | [Errors & Failures Reference](errors-and-failures.md) | `ExecutionFailureKind` + classified failures |
| Architecture graph vocabulary і projections | [Architecture Graph Reference](architecture-graph.md) | Visualization vocabulary / projection registry |

## Ручний довідник

Не все варто генерувати. Meaning і cross-cutting explanation залишаються human-maintained.

### [Глосарій](glossary.md)

Канонічні терміни й правила їх використання.

### [Компоненти Kernel](kernel-components.md)

Навігаційний довідник основних Kernel mechanisms і їхньої відповідальності.

## Як вибрати джерело

```text
«Навіщо?»
→ Concept / Product / ADR

«Як працює бізнес-процес?»
→ Workflow + ProcessDiagram

«Де процеси перетинають межі Domains?»
→ Cross-Domain Process Topology

«Які installable Domains мають process model?»
→ Domain Process Coverage

«Хто відповідає за process steps і що mapped у runtime?»
→ Business Process Registry

«Які capability gaps уже визнані?»
→ Capability Debt Backlog

«Хто володіє Domain semantics?»
→ Domain / Architecture

«Як виконується?»
→ Runtime

«Яке точне ім’я/version/route/event?»
→ Generated Reference

«Де код?»
→ Карта коду + generated source path
```

## Захист від drift

CI генерує reference з поточного `main`, перевіряє committed output byte-for-byte, після чого запускає contracts/process/knowledge checks і VitePress build.

Generated layer охоплює modules/capabilities, extension points, use cases, commands, events, routes, permissions, configuration ownership, database migrations, execution failures, architecture graph vocabulary, Business Process Registry, Cross-Domain Process Topology, Domain Process Coverage та Capability Debt Backlog.

Process Registry schema v5 додає contract-guarded cross-domain steps поверх backward-compatible v4 same-domain definitions. Cross-Domain Process Topology агрегує лише foreign-domain steps із verified `requires` contract та target-Domain capability. Capability Debt Registry вимагає matching debt item для process capability gap і відхиляє stale debt після появи target capability. Domain Process Coverage вимагає process model або explicit exemption для кожного installable Domain і не плутає supporting directory з module manifest.

Зміна executable або structured contract без синхронізації knowledge layer має бути build defect, а не сюрпризом через два місяці.
