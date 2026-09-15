---
title: Visualization V0.4.1 — Architecture Explorer Hardening
description: Automation topology, dependency evidence and projection-aware Architecture Explorer behavior.
status: implemented
kind: architecture
updated: 2026-09-15
---

# Visualization V0.4.1 — Architecture Explorer Hardening

Visualization V0.4.1 turns the Explorer from a renderer over module metadata into a stronger architecture inspection surface. The graph remains derived from canonical COS contracts; the hardening deliberately avoids regex/static-source guessing.

```text
ModuleCatalog + DomainModuleRegistry
              ↓
      ArchitectureGraphProvider
              ↓
        Canonical Graph
        ↙            ↘
 dependency facts   automation topology
              ↓
   ArchitectureProjectionRegistry
              ↓
 server-side GraphView projection
              ↓
       Cytoscape Explorer
```

## Automation topology

The canonical graph now represents bootstrap automation definitions explicitly:

```text
Event ──triggers──> Rule ──produces──> Action ──handled_by──> Handler
                                      ↑
Agent ─────────────proposes────────────┤
Policy ────────────governs─────────────┘
```

New node types are `rule` and `policy`. New relations are `triggers`, `produces` and `governs`.

Rules and policies exposed here are **bootstrap defaults**, not effective tenant configuration. Kernel therefore has explicit `BootstrapRuleProvidingModuleInterface` and `BootstrapPolicyProvidingModuleInterface` contracts. Tenant-aware `RuleProvidingModuleInterface` / `PolicyProvidingModuleInterface` remain separate and continue to model organization-specific runtime state.

Sales exposes its existing provisioning catalogs through the bootstrap contracts. Visualization consumes only those contracts; it does not call `rules('default')` or `policies('default')` itself.

## Dependency evidence

`depends_on` is no longer an anonymous edge. Dependency metadata records where the fact came from.

Current canonical evidence sources include:

- `manifest.kernel_constraint`: every catalog module depends on the COS Kernel contract it declares;
- `manifest.dependencies`: explicit module dependency declarations;
- `runtime_module_contract`: runtime-only modules still depend on Kernel contracts;
- `automation.agent`: an agent proposes an action owned by another Domain;
- `automation.rule.trigger`: a bootstrap rule consumes an event owned by another Domain;
- `automation.rule.effect`: a bootstrap rule produces an action owned by another Domain;
- `automation.policy`: a bootstrap policy governs an action owned by another Domain.

Derived cross-domain dependencies are created only after canonical Event/Action ownership is known. V0.4.1 does **not** infer arbitrary PHP import dependencies or scan source code for architectural meaning.

## Projection-aware layouts

Projection definitions expose renderer-neutral layout intent through the Kernel registry description contract.

| Projection | Layout intent |
| --- | --- |
| System | hierarchical |
| Runtime | flow |
| Domain | radial |
| Dependencies | hierarchical |
| Events | flow |
| Actions | flow |
| Agents | radial |
| Integrations | hierarchical |
| Code | force |

The browser maps these hints to Cytoscape algorithms. Renderer-specific algorithm names remain outside Kernel and Architecture graph semantics.

## Server-side Domain focus

The Explorer now exposes a manager-only read endpoint:

```text
GET /cos/architecture/graph?view=domain&focus=domain:sales&depth=2
```

`focus` and `depth` are applied by `ArchitectureGraphProjection` on the server through `GraphView`. The browser no longer receives the full Domain graph merely to rediscover the same neighborhood itself. `depth=all` uses the full projection.

This matters once the architecture graph grows from dozens to thousands of nodes: transport and projection stay bounded instead of turning the browser into an accidental architecture engine.

## Explorer hardening

The active projection now owns its visible statistics and filters. Switching views updates node/edge totals, available node types and layout intent.

Node details expose more than raw metadata: projection/layout, version/source, Domain ownership breakdown, incoming/outgoing relations and edge provenance. Raw metadata remains available as a secondary diagnostic view.

## Boundary rules

- `Kernel\\Visualization` remains renderer- and Architecture-agnostic.
- Bootstrap automation contracts belong to Kernel Module contracts because provisioning, documentation and visualization can all consume them.
- Tenant-effective Rules/Policies are not embedded into the platform-level Architecture Graph.
- Architecture semantics remain under `Infrastructure\\Visualization\\Architecture`.
- Web compiles only against Kernel graph/projection contracts.
- Cytoscape remains a renderer, not a source of architecture truth.
- V0.4.1 strengthens known facts; it does not invent dependencies that COS has not yet declared or exposed through canonical runtime ownership.
