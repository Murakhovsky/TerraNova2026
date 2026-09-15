---
title: Visualization V0.3 — Architecture Explorer
description: Interactive Cytoscape projection of the canonical COS Architecture Graph.
status: implemented
---

# Visualization V0.3 — Architecture Explorer

Visualization V0.3 adds the first interactive renderer on top of the renderer-independent graph foundation introduced in V0.1–V0.2.

The source of truth remains the COS graph model:

```text
ModuleCatalog + DomainModuleRegistry
        ↓
ArchitectureGraphProvider
        ↓
Kernel\\Visualization\\Graph\\Graph
        ↓
CytoscapeGraphMapper
        ↓
Architecture Explorer
```

Cytoscape does not own architecture semantics. It receives a projection containing nodes, edges, metadata and stable relation types.

## Web surface

Manager/admin users can open:

```text
/cos/architecture
```

The workspace provides System, Runtime and Domain modes, node-type filters, domain focus, depth filtering, search, zoom/pan, fit/reset, node metadata and local neighborhood collapse/expand.

## Adapter boundary

`Infrastructure\\Visualization\\Cytoscape\\CytoscapeGraphMapper` is the only PHP adapter that knows the Cytoscape element shape. `Kernel\\Visualization` contains no Cytoscape references.

The browser runtime uses the pinned Cytoscape.js `3.34.3` build. The graph payload is embedded as `application/json`, not executable inline JavaScript.

## Deliberate limits

V0.3 does not add new architecture semantics, projections or server-side view models. Those belong to Visualization V0.4. It also does not add Mermaid or BPMN.
