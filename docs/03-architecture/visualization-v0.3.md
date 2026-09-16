---
title: Visualization V0.3 — оглядач архітектури
description: Інтерактивна Cytoscape-проєкція канонічного Architecture Graph COS.
status: implemented
kind: architecture
updated: 2026-09-16
---

# Visualization V0.3 — оглядач архітектури

Visualization V0.3 додав перший інтерактивний renderer поверх renderer-independent graph foundation, створеного у V0.1–V0.2.

Джерелом істини залишається модель графа COS:

```text
ModuleCatalog + DomainModuleRegistry
        ↓
ArchitectureGraphProvider
        ↓
Kernel\Visualization\Graph\Graph
        ↓
CytoscapeGraphMapper
        ↓
Architecture Explorer
```

Cytoscape не володіє архітектурною семантикою. Він отримує projection із nodes, edges, metadata та stable relation types.

## Web surface

Manager/admin користувачі відкривають:

```text
/cos/architecture
```

Workspace підтримує System, Runtime і Domain modes, node-type filters, domain focus, depth filtering, search, zoom/pan, fit/reset, node metadata та локальне collapse/expand сусідів.

## Adapter boundary

`Infrastructure\Visualization\Cytoscape\CytoscapeGraphMapper` є PHP adapter, який знає Cytoscape element shape. `Kernel\Visualization` не містить Cytoscape-specific references.

Browser runtime використовує pinned Cytoscape.js `3.34.3`. Graph payload вбудовується як `application/json`, а не executable inline JavaScript.

## Межі версії

V0.3 не додавав нову architecture semantics, canonical projections або server-side view models. Це стало наступним етапом Visualization V0.4. Mermaid і BPMN у V0.3 також не входили.
