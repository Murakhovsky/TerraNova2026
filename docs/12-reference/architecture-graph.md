---
title: Architecture Graph Reference
description: Generated vocabulary and projection catalogue for the canonical COS Architecture Graph.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Architecture Graph Reference

> Джерело істини: `ArchitectureGraphVocabulary` + `ArchitectureProjectionRegistry` у current checkout.

## Node types

| Constant | Value |
| --- | --- |
| `TYPE_ACTION` | `action` |
| `TYPE_AGENT` | `agent` |
| `TYPE_CAPABILITY` | `capability` |
| `TYPE_CONTRACT` | `contract` |
| `TYPE_DOMAIN` | `domain` |
| `TYPE_EVENT` | `event` |
| `TYPE_EXTENSION_POINT` | `extension_point` |
| `TYPE_HANDLER` | `handler` |
| `TYPE_KERNEL` | `kernel` |
| `TYPE_POLICY` | `policy` |
| `TYPE_RULE` | `rule` |
| `TYPE_SERVICE` | `service` |

## Relations

| Constant | Value |
| --- | --- |
| `REL_CONTAINS` | `contains` |
| `REL_CONTRIBUTES` | `contributes` |
| `REL_CONTRIBUTES_TO` | `contributes_to` |
| `REL_DEPENDS_ON` | `depends_on` |
| `REL_GOVERNS` | `governs` |
| `REL_HANDLED_BY` | `handled_by` |
| `REL_OWNS` | `owns` |
| `REL_PRODUCES` | `produces` |
| `REL_PROPOSES` | `proposes` |
| `REL_PROVIDES_CONTRACT` | `provides_contract` |
| `REL_REQUIRES_CONTRACT` | `requires_contract` |
| `REL_TRIGGERS` | `triggers` |

## Canonical projections

| Projection | Label |
| --- | --- |
| `system` | System |
| `runtime` | Runtime |
| `domain` | Domain |
| `dependencies` | Dependencies |
| `contracts` | Contracts |
| `events` | Events |
| `actions` | Actions |
| `agents` | Agents |
| `integrations` | Integrations |
| `code` | Code |

## Executable surface

The live Architecture Explorer is exposed by the Web interface at `/cos/architecture`; this page documents the vocabulary behind that executable graph rather than duplicating its rendering.
