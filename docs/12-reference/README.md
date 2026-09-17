---
title: Індекс технічного довідника
description: Карта точного/generated reference COS і правила вибору правильного джерела фактів.
status: active
updated: 2026-09-17
kind: reference
contract: reference-v1
---

# Індекс технічного довідника

Цей розділ відповідає на питання: **що executable code або machine-readable documentation contracts декларують точно?**

Якщо потрібен сенс, починайте з Workflow, Domain або Architecture. Якщо потрібні точні names, versions, routes, capabilities, process ownership, entity/state boundaries, cross-domain boundaries, Domain process coverage, capability debt чи runtime mappings, дивіться Reference. Інакше prose дуже швидко стає базою даних, тільки гіршою.

Поточна максимальна Process Registry schema: **v5**. Same-domain definitions також підтримують schema **v4**.

Capability Debt Registry schema: **v1**.

Process Coverage Exemptions schema: **v1**.

Process Use-Case Coverage schema: **v1**; exemption schema: **v1**.

Entity & State Registry schema: **v1**.

## Модель авторитетності

```text
main executable metadata / code
        ↓ factual authority
explicitly reviewed documentation snapshot
        +
structured documentation contracts
        ↓
COS generators + source checks
        ↓
generated Markdown
        ↓ byte-for-byte sync
documentation:/docs/12-reference
        ↓
VitePress Web
```

`main` залишається factual authority для runtime/domain фактів. Гілка `documentation` є незалежним knowledge workspace: source-backed registries фіксують revision `main`, проти якого їх факти були перевірені, а documentation CI перевіряє внутрішню узгодженість поточного checkout. Зелений build сам по собі **не** означає, що `documentation` телепатично синхронізована з найновішим `main`.

Generated files у `docs/12-reference` не редагуються вручну. Зміна їхньої структури або мови робиться в generator/structured authority, після чого generated output комітиться разом зі зміною.

## Згенерований довідник

| Потрібно дізнатися | Відкрити | Авторитетне джерело |
| --- | --- | --- |
| Канонічні бізнес-процеси, ownership, topology, capability coverage і runtime verification | [Business Process Registry](business-processes.md) | `resources/processes/*.json` + checkout runtime evidence |
| Чи всі installable Application Use Cases представлені canonical process semantics | [Process Use-Case Coverage](process-use-case-coverage.md) | Domain `Application/UseCase` structure + Process Registry mappings + explicit exemptions |
| Канонічні бізнес-сутності, ownership та окремі state vocabularies | [Entity & State Registry](entity-states.md) | `entity-state-registry.json` + reviewed `main` source facts + checkout source verification |
| Які процеси перетинають Domain boundaries і через які contracts/capabilities | [Cross-Domain Process Topology](cross-domain-process-topology.md) | Process Registry v5 + checkout contract evidence |
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

«Чи всі executable Use Cases представлені процесами?»
→ Process Use-Case Coverage

«Які сутності існують і що означає їх status/state?»
→ Entity & State Registry

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

CI генерує і верифікує reference з **поточного `documentation` checkout**. `main` залишається factual authority; `reviewed_main_revision` у source-backed registries фіксує revision `main`, проти якого відповідні факти були перевірені. Автоматичний inter-branch freshness gate є окремою governance-вимогою і не підміняється зеленим build.

Generated layer охоплює modules/capabilities, extension points, use cases, commands, events, routes, permissions, configuration ownership, database migrations, execution failures, architecture graph vocabulary, Business Process Registry, Process Use-Case Coverage, Entity & State Registry, Cross-Domain Process Topology, Domain Process Coverage та Capability Debt Backlog.

Process Registry schema v5 додає contract-guarded cross-domain steps поверх backward-compatible v4 same-domain definitions. Cross-Domain Process Topology агрегує лише foreign-domain steps із verified `requires` contract та target-Domain capability. Capability Debt Registry вимагає matching debt item для process capability gap і відхиляє stale debt після появи target capability. Domain Process Coverage вимагає process model або explicit exemption для кожного installable Domain і не плутає supporting directory з module manifest. Process Use-Case Coverage вимагає mapping або explicit exemption для кожного installable Application Use Case. Entity & State Registry звіряє entity ownership, source symbols, process touchpoints і exact state vocabulary з source.

Зміна executable або structured contract без синхронізації knowledge layer має бути build defect, а не сюрпризом через два місяці.
