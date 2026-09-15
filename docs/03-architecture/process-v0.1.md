---
title: Process V0.1 — Canonical Process Foundation
description: Platform-level Process Registry authority, Kernel structural model and schema-v4/v5 cross-domain contract boundary.
status: active
updated: 2026-09-15
kind: architecture
contract: architecture-v1
---

# Process V0.1 — Canonical Process Foundation

Process V0.1 moves business-process topology out of documentation tooling and gives COS a platform-level Process model.

The architectural chain remains:

```text
Business
  ↓
Workflow
  ↓
Process Registry
  ↓
Domain
  ↓
Capability
  ↓
Runtime evidence / contract
  ↓
Service / Code
```

## Canonical authority

The machine-readable source of truth is:

```text
resources/processes/*.json
```

`docs/.vitepress` is a consumer and projection layer. It no longer owns a second copy of process definitions.

The current registry contains the four existing canonical process definitions. Process V0.1 does not invent new workflows, capabilities or mappings while moving authority.

## Kernel structural model

Kernel owns the stable process vocabulary:

```text
Kernel\Process\ProcessDefinition
Kernel\Process\ProcessStep
Kernel\Process\ProcessEdge
Kernel\Process\RuntimeMapping
Kernel\Process\ProcessRegistryInterface
```

Infrastructure provides:

```text
Infrastructure\Process\JsonProcessRegistry
```

Bootstrap registers:

```text
cosProcessRegistry
  → BASE_PATH/resources/processes
```

Kernel version advances additively to `0.11.9`.

## Schema compatibility

Process V0.1 deliberately supports the current schema transition rather than forcing a fake rewrite:

```text
schema v4
  same-domain process steps

schema v5
  same-domain steps
  + contract-guarded cross-domain steps
```

Existing schema-v4 definitions remain valid.

## Cross-domain boundary

A schema-v5 step may belong to another Domain, but the boundary is split into two validation levels.

### Kernel structural invariant

If:

```text
step.domain != process.domain
```

then:

```text
schema >= 5
AND
step.runtime contains a contract mapping
```

Kernel checks structure only. It does not depend on ModuleCatalog, Visualization or documentation evidence.

### Evidence semantic invariant

The current-checkout evidence layer performs the stronger proof. A cross-domain step is valid only when its contract resolves to evidence where:

```text
evidence.domain      == process.domain
evidence.role        == requires
evidence.counterpart == step.domain
```

This keeps the architecture boundary clean:

```text
Kernel
  → shape and invariants

Module / Runtime evidence
  → whether the declared boundary really exists
```

## Canonical example

`sales.request-to-property-match` is the first schema-v5 process exercising this model:

```text
Sales process
  ↓
requires PropertyReferencePort
  ↓
Property / property.reference
  ↓
Sales Property Match
```

The `resolve-property` step belongs to Property and uses `property.reference`. Sales still owns the root process, Client Case and match relationship. Cross-domain execution does not create shared state ownership.

## Documentation projections

Documentation reads the same platform authority through a thin adapter:

```text
resources/processes
        ↓
process-registry.mjs
        ↓
check-processes
Capability Debt
Knowledge Health
Domain Process Coverage
Business Process Reference
ProcessDiagram
```

`ProcessDiagram` derives four views from the same definition:

- flow;
- ownership;
- capability;
- domain for cross-domain workflows.

Generated Markdown remains a projection and is never runtime evidence or process authority.

## Relationship to existing COS architecture

Process is not a replacement for Domains or Runtime.

```text
Process
  describes business work topology

Domain
  owns business semantics and state

Capability
  names what a Domain can do

Contract
  allows declared cross-domain interaction

Runtime evidence
  verifies that mappings exist in the current checkout

Visualization
  projects these facts for humans
```

This preserves the wider COS rule:

```text
Business → Workflow → Domain → Capability → Runtime → Service → Code
```

Process Registry is the canonical bridge between Workflow and the Domain/Capability layers.

## Drift protection

Process V0.1 adds a dedicated architecture and CI boundary.

The gates verify:

- Kernel remains independent of docs and Infrastructure;
- `resources/processes` is the only canonical JSON source;
- schema v4 and v5 are both supported;
- cross-domain v5 steps require structural contract mappings;
- the Sales → Property process preserves `property.reference` and `PropertyReferencePort`;
- documentation consumers use the platform registry;
- Visualization V0.5 evidence semantics remain valid;
- Capability Debt, Knowledge Health and Domain Process Coverage remain coherent;
- generated reference stays byte-for-byte synchronized.

## Non-goals of V0.1

Process V0.1 does **not** introduce:

- a process execution engine;
- BPMN as source of truth;
- orchestration ownership inside Kernel;
- observed production-trace verification;
- inferred cross-domain contracts;
- automatic capability invention.

Those would be separate architectural versions. Turning a registry into an execution engine because both contain arrows is exactly the kind of shortcut that later requires an EPIC called “undo everything”.

## Result

After V0.1 COS has one canonical process authority usable by runtime services, architecture checks, documentation and visualization without making documentation tooling part of the platform model.
