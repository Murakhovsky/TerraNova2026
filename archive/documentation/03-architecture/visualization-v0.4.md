---
title: Visualization V0.4 — архітектурні проєкції
description: Канонічні server-side views над COS Architecture Graph.
status: implemented
kind: architecture
updated: 2026-09-16
---

# Visualization V0.4 — архітектурні проєкції

Visualization V0.4 переніс view semantics із browser у server-side architecture layer. Cytoscape продовжує рендерити граф, але вже не вирішує сам, що означають `System`, `Runtime` або `Dependencies`.

```text
ArchitectureGraphProvider
        ↓
Canonical Graph
        ↓
ArchitectureProjectionRegistry
        ↓
GraphProjectionInterface
        ↓
Projected Graph
        ↓
CytoscapeGraphMapper
        ↓
Explorer
```

## Канонічні проєкції етапу V0.4

Registry на цьому етапі експонував дев’ять projections з одного canonical graph:

```text
system
runtime
domain
dependencies
events
actions
agents
integrations
code
```

Кожна projection володіє своїми allowed node types та relation vocabulary.

`GraphView` залишається generic runtime request і може додатково обмежувати node types, relations, focus та depth без зміни source graph.

`domain` projection підтримує focused neighborhood із default depth `2`. Interactive Web explorer може локально звужувати domain/depth уже після server-side semantic projection.

## Правила меж

`Kernel\Visualization` знає лише generic contracts:

- `GraphProjectionInterface`;
- `GraphProjectionRegistryInterface`;
- `Graph`;
- `GraphView`;
- filters.

Kernel не знає Architecture або Cytoscape vocabulary.

Architecture projection definitions живуть під `Infrastructure\Visualization\Architecture`, а composition реєструється через `Bootstrap\VisualizationServices`.

Web controller залежить від Kernel registry contract і отримує вже projected graph. Browser code перемикає server-provided projection payloads замість hardcoded наборів типів на кшталт `SYSTEM_TYPES` чи `RUNTIME_TYPES`.

## Межі етапу

V0.4 проєктував лише факти, які вже існували в canonical Architecture Graph. Rules і Policies не мали вигадуватися до появи canonical registry/catalog sources.

`code` view тому показував implementation-bearing nodes, які реально існували, наприклад services, actions і handlers, а не удавав, що COS вже має повний static-analysis graph.

Подальші V0.4.1–V0.4.2 розширили цю основу automation topology, dependency evidence та first-class cross-domain contracts.
