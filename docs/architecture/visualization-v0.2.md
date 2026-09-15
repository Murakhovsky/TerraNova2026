---
title: Visualization V0.1–V0.2
description: Canonical graph foundation and architecture graph registry for COS.
status: implemented
---

# Visualization V0.1–V0.2

Visualization is a platform capability, not a business Domain. The Kernel owns only renderer-independent graph and diagram contracts. Infrastructure projects current COS architecture into that model.

## Canonical flow

```text
ModuleCatalog + DomainModuleRegistry
              ↓
    ArchitectureGraphProvider
              ↓
            Graph
              ↓
      future Projections
              ↓
 future Cytoscape / Mermaid / other renderers
```

## V0.1 — Graph foundation

`app/Kernel/Visualization` defines:

- `Node`, `Edge`, `Group`, `Graph`;
- `GraphFilter`, `GraphView`;
- `GraphProviderInterface`, `GraphProjectionInterface`;
- generic `Diagram` and `DiagramRendererInterface`.

The Kernel contains no Cytoscape, Mermaid, BPMN or Web-specific types.

## V0.2 — Architecture graph registry

`ArchitectureGraphProvider` reads existing canonical registries instead of inventing a parallel architecture database:

- `ModuleCatalog` supplies manifests, dependencies, capabilities and module service contributions;
- `DomainModuleRegistry` supplies live domain events, actions, handlers and agents.

Current node vocabulary:

`kernel`, `domain`, `capability`, `event`, `action`, `agent`, `service`, `handler`, `extension_point`.

Current relation vocabulary:

`contains`, `depends_on`, `owns`, `contributes`, `contributes_to`, `handled_by`, `proposes`.

Rules and policies are intentionally not synthesized yet. They should enter the graph only when COS exposes equally canonical registry/catalog sources for them.

## Runtime service

The provider is registered as `cosArchitectureGraphProvider` and can be consumed by future Web/CLI/documentation projections without changing the Kernel model.

## Gates

- `tests/unit/visualization_graph_foundation.php`
- `tests/unit/visualization_architecture_graph.php`
- `tests/architecture/visualization_v02.php`

The next release can build the interactive explorer against this graph without moving renderer concerns into the Kernel.
