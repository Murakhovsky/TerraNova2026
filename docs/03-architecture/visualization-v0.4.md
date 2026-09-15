---
title: Visualization V0.4 — Architecture Projections
description: Canonical server-side views over the COS Architecture Graph.
status: implemented
kind: architecture
updated: 2026-09-15
---

# Visualization V0.4 — Architecture Projections

Visualization V0.4 moves view semantics out of the browser. Cytoscape still renders graphs, but it no longer decides what “System”, “Runtime” or “Dependencies” mean.

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

## Canonical projections

The registry exposes nine projections from the same canonical graph: `system`, `runtime`, `domain`, `dependencies`, `events`, `actions`, `agents`, `integrations` and `code`.

Each projection owns its allowed node types and relation vocabulary. `GraphView` remains the generic runtime request and can further constrain node types, relations, focus and depth without changing the source graph.

The `domain` projection supports a focused neighborhood with a default depth of two. The interactive Web explorer keeps domain/depth narrowing local after the server has already applied the semantic projection.

## Boundary rules

`Kernel\\Visualization` knows only `GraphProjectionInterface`, `GraphProjectionRegistryInterface`, `Graph`, `GraphView` and filters. It has no Architecture or Cytoscape vocabulary.

Architecture projection definitions live under `Infrastructure\\Visualization\\Architecture`. Their composition is registered by `Bootstrap\\VisualizationServices`.

The Web controller depends on the Kernel registry contract and receives already-projected graphs. Browser code switches between server-provided projection payloads instead of carrying hard-coded `SYSTEM_TYPES` or `RUNTIME_TYPES` sets.

## Current limits

V0.4 projects only facts already present in the canonical Architecture Graph. Rules and Policies are not invented until canonical registry/catalog sources exist. The `code` view therefore exposes current implementation-bearing nodes such as services, actions and handlers rather than pretending COS already has a full static-analysis graph.
