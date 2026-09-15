---
title: Process V0.1 — Canonical Process Foundation
description: Platform-owned business process model and registry shared by runtime, documentation, visualization, evidence and architecture health tooling.
status: active
updated: 2026-09-15
kind: architecture
contract: architecture-v1
---

# Process V0.1 — Canonical Process Foundation

Process V0.1 removes the remaining ownership inversion where canonical business-process definitions lived inside VitePress tooling. It does **not** introduce a workflow engine. It introduces a stable platform model that other COS layers can consume without making Documentation the owner of runtime truth.

## Canonical chain

```text
resources/processes/*.json
        ↓
Kernel\\Process
ProcessDefinition / ProcessStep / ProcessEdge / RuntimeMapping
        ↓
ProcessRegistryInterface
        ↓
Infrastructure\\Process\\JsonProcessRegistry
        ↓
cosProcessRegistry
        ↓
├── runtime consumers
├── Visualization / Mermaid projections
├── Documentation reference
├── Runtime Evidence
├── Capability Debt
├── Knowledge Health
└── Domain Process Coverage
```

`resources/processes` is the canonical structured source. `docs/.vitepress` may validate, render and generate projections, but it no longer owns a second Process Registry.

## Kernel boundary

`Kernel\\Process` knows the structural process contract only. It has no dependency on:

- VitePress or Markdown;
- Mermaid or Cytoscape;
- ArchitectureGraph;
- ModuleCatalog;
- Infrastructure adapters.

Kernel validates process topology and schema shape. Current-checkout evidence tooling validates whether referenced capabilities, events, contracts and source mappings actually exist.

## Schema v4 preservation

Process V0.1 deliberately adopts the existing Process Registry schema v4 rather than replacing it.

Each step retains:

```text
step
├── owner
├── domain
├── capability OR explicit capability_gap
├── critical
└── runtime mappings
```

The current v4 invariant remains: a step belongs to the process Domain. Cross-domain process steps require a future explicit semantic contract rather than silently borrowing another Domain capability.

## Runtime registry

Bootstrap exposes:

```text
cosProcessRegistry : Kernel\\Process\\ProcessRegistryInterface
```

The first adapter is `Infrastructure\\Process\\JsonProcessRegistry`, backed by `BASE_PATH/resources/processes`.

This gives runtime and future Visualization code a renderer-neutral way to access canonical processes without reading documentation internals.

## Documentation adapter

`docs/.vitepress/process-registry.mjs` is intentionally thin. It resolves the same `resources/processes` authority for Node-based documentation checks and generators.

Existing DOC V0.15–V0.18 semantics remain independent consumers:

- capability mapping and gaps;
- Capability Debt Registry;
- Knowledge Health;
- Domain Process Coverage;
- flow / ownership / capability Mermaid projections;
- evidence-backed verification.

## What V0.1 is not

V0.1 does not execute process steps, persist process instances, schedule transitions, or infer entity state machines. Those require separate execution semantics and observed runtime evidence.

The boundary is deliberate:

```text
Process Definition != Process Execution
Process Step != Entity State
Process Diagram != Runtime Trace
```

## Versioning

The additive Kernel contract moves from `0.11.8` to `0.11.9`. Existing module constraints `>=0.11.0 <0.12.0` remain compatible.

## Gates

Process V0.1 CI verifies:

- schema-v4 Kernel hydration;
- canonical `resources/processes` ownership;
- absence of a second docs-owned registry;
- Bootstrap service registration;
- capability/gap preservation;
- DOC V0.15 runtime evidence compatibility;
- DOC V0.16 Capability Debt compatibility;
- DOC V0.17 Knowledge Health compatibility;
- DOC V0.18 Domain Process Coverage compatibility;
- Documentation generation/build.
