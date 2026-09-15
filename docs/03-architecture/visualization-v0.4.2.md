---
title: Visualization V0.4.2 — Cross-Domain Dependency Audit
description: Machine-readable cross-domain contract topology and CI enforcement for COS bounded-context boundaries.
status: implemented
kind: architecture
updated: 2026-09-15
---

# Visualization V0.4.2 — Cross-Domain Dependency Audit

V0.4.2 closes a gap discovered by the Architecture Explorer: module `dependencies` describe module/runtime requirements, but they do not describe every legitimate business interaction between bounded contexts.

A consumer-owned port must not be flattened into a generic `Domain A depends_on Domain B` edge. That loses ownership semantics and can manufacture circular module dependencies where the code actually uses dependency inversion correctly.

## Canonical model

Cross-domain synchronous boundaries are declared in `ModuleContributions.cross_domain_contracts`:

```text
contract
role: requires | provides
counterpart
kind
purpose
```

The declaring module plus role resolves two semantic participants:

```text
Consumer Domain ── requires_contract ──▶ Contract
Provider Domain ── provides_contract ──▶ Contract
```

The contract class namespace records vocabulary ownership separately from consumer/provider roles.

Runtime Visualization never scans PHP source to invent this graph. Source scanning exists only in CI as a verification mechanism.

## Current AS-IS contract topology

### Sales → Property

`Domains\Property\Contract\PropertyReferencePort`

Sales requires the canonical Property reference boundary. Property remains authority for Property state.

### Property → Sales

`Domains\Property\Application\Contract\PresentationSalesInterface`

Property presentation requires Sales-owned client-case/share context. The port is consumer-owned by Property and implemented by Sales.

### Spatial → Property

`Domains\Spatial\Application\Contract\PropertyTourPublisherInterface`

Spatial owns the publication boundary; Property provides canonical tour data through an adapter.

## Contracts projection

Architecture Explorer adds a dedicated `Contracts` projection containing:

- Domain nodes;
- Contract nodes;
- `owns` vocabulary ownership;
- `requires_contract` consumer edges;
- `provides_contract` provider edges.

`Dependencies` also includes contract nodes, but ordinary `depends_on` remains reserved for module/runtime dependency evidence.

## CI audit

`tests/architecture/cross_domain_dependency_audit.php` scans `app/Domains/*` and classifies every cross-domain class reference.

Allowed:

1. exact class declared as a canonical cross-domain contract;
2. exact legacy debt entry listed in the audit allowlist.

Everything else fails CI.

The allowlist is deliberately exact by file and class. A folder named `Legacy` is not diplomatic immunity.

## Known legacy debt

The audit found seven explicit direct Infrastructure references concentrated in four old Telegram/Phalcon files:

- Sales `Shows.php` → Property `Objects`;
- Sales `Shows.php` → Identity `AppUsers`;
- Sales `Requests.php` → Identity `ListItems` and `Lists`;
- Property `Objects.php` → Identity `ListItems`;
- Property `ReObjects.php` → Identity `AppUsers` and `Lists`.

These are tolerated only as named migration debt. New references of the same shape are rejected.

## Why this matters for V0.5

Business Process Visualization can now reference canonical Domain and Contract nodes instead of redrawing integration semantics inside Mermaid source.

The intended chain becomes:

```text
BusinessProcessDefinition
        ↓
Process / Runtime evidence
        ↓
Domain + Contract topology
        ↓
Diagram projection
        ↓
Mermaid / later BPMN
```

`AS-IS`, `TO-BE` and `RUNTIME-VERIFIED` remain process evidence states. They belong to the process model/projection, not to the Mermaid renderer.
