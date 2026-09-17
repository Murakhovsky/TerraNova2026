---
title: Entity & State Registry
description: Canonical COS business entities, ownership boundaries and source-verified state vocabularies.
status: generated
updated: 2026-09-17
kind: reference
contract: reference-v1
generated: true
---

# Entity & State Registry

> Structured registry schema v1. Business facts were reviewed against main revision `25d256a144cdb6098f895e21df75d3acab405132`; this generated projection is verified against the current documentation checkout.

This registry names only canonical business entities and aggregate-like persisted concepts. A PHP class is not promoted to a business entity merely because it exists.

## Summary

- **Canonical entities:** 9
- **Explicit state models:** 9
- **Installable Domains represented:** 3
- **Canonical process touchpoints:** 24

| Domain | Entities | State models | Process touchpoints |
| --- | ---: | ---: | ---: |
| `diagnostic` | 2 | 2 | 8 |
| `property` | 4 | 4 | 5 |
| `sales` | 3 | 3 | 11 |

## Canonical entities and states

| Entity | Domain | Representation | Identity boundary | State semantics | States | Terminal / closed | Process touchpoints | Source |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **Diagnostic Pack**<br>`diagnostic.pack` | `diagnostic` | `domain-model` | pack id + immutable version | `versioned-lifecycle`<br>DiagnosticPackStatus | `draft`, `published`, `retired` | terminal: `retired` | `diagnostic.session-to-recommendation:methodology`<br>`diagnostic.session-to-recommendation:revision` | `app/Domains/Diagnostic/Model/DiagnosticPack.php` |
| **Diagnostic Session**<br>`diagnostic.session` | `diagnostic` | `domain-model` | session id pinned to pack id + version | `lifecycle`<br>DiagnosticSessionStatus | `planned`, `in_progress`, `completed`, `cancelled` | terminal: `completed`, `cancelled` | `diagnostic.session-to-recommendation:session`<br>`diagnostic.session-to-recommendation:evidence`<br>`diagnostic.session-to-recommendation:evaluation`<br>`diagnostic.session-to-recommendation:result`<br>`diagnostic.session-to-recommendation:complete`<br>`diagnostic.session-to-recommendation:cancelled` | `app/Domains/Diagnostic/Model/DiagnosticSession.php` |
| **Property Asset**<br>`property.asset` | `property` | `domain-model` | tenant-scoped stable canonical asset id | `physical-lifecycle`<br>PropertyLifecycle | `unknown`, `planned`, `under_construction`, `completed`, `renovation`, `damaged`, `demolished` | terminal: `demolished` | `property.submission-to-publication:asset`<br>`sales.request-to-property-match:resolve-property` | `app/Domains/Property/Model/PropertyAsset.php` |
| **Inventory Item**<br>`property.inventory` | `property` | `domain-model` | organization inventory id referencing one canonical Property Asset | `commercial-state`<br>InventoryStatus | `available`, `reserved`, `on_hold`, `under_offer`, `sold`, `rented`, `off_market`, `withdrawn` | closed: `sold`, `rented`, `withdrawn` | `property.submission-to-publication:inventory` | `app/Domains/Property/Model/InventoryItem.php` |
| **Listing**<br>`property.listing` | `property` | `domain-model` | listing id presenting organization inventory to the market | `presentation-lifecycle`<br>ListingStatus | `draft`, `ready`, `published`, `hidden`, `expired`, `archived` | — | `property.submission-to-publication:listing` | `app/Domains/Property/Model/Listing.php` |
| **Publication**<br>`property.publication` | `property` | `domain-model` | channel-specific publication id for a Listing | `channel-state`<br>PublicationState | `draft`, `queued`, `published`, `hidden`, `expired`, `failed`, `archived` | — | `property.submission-to-publication:publication` | `app/Domains/Property/Model/Publication.php` |
| **Lead**<br>`sales.lead` | `sales` | `application-persisted` | tenant-scoped inbound demand record linked into Sales canonical state | `demand-qualification`<br>LeadStatus | `new`, `contacted`, `qualified`, `viewing_planned`, `viewing`, `negotiation`, `won`, `lost`, `disqualified`, `spam`, `closed` | — | `sales.lead-to-managed-case:intake`<br>`sales.lead-to-managed-case:canonical-state` | `app/Domains/Sales/Application/UseCase/ReceivePublicLead.php` |
| **Client Case / Deal**<br>`sales.client-case` | `sales` | `application-persisted` | tenant-scoped client case id representing a managed sales process | `case-lifecycle`<br>ClientCaseStatus | `active`, `paused`, `closed`, `lost` | terminal: `closed`, `lost` | `sales.lead-to-managed-case:canonical-state`<br>`sales.lead-to-managed-case:assign-owner`<br>`sales.lead-to-managed-case:pipeline`<br>`sales.lead-to-managed-case:activity`<br>`sales.lead-to-managed-case:followup`<br>`sales.lead-to-managed-case:outcome`<br>`sales.request-to-property-match:create-case` | `app/Domains/Sales/Application/Service/ClientCaseCommandService.php` |
| **Property Match**<br>`sales.property-match` | `sales` | `association-record` | Sales-owned association between Client Case and referenced Property | `demand-supply-match`<br>PropertyMatchStatus | `suggested`, `sent`, `interested`, `viewing`, `rejected`, `deal` | — | `sales.request-to-property-match:record-match`<br>`sales.request-to-property-match:activity-events` | `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php` |

## Entity boundaries

### Property

```text
Property Asset ≠ Inventory Item ≠ Listing ≠ Publication
physical lifecycle ≠ commercial state ≠ market presentation ≠ channel state
```

`sold` and `rented` are Inventory states. They do not erase or terminate the physical Property Asset. A Listing or Publication may be hidden, expired or archived without changing the identity or physical lifecycle of the Property Asset.

### Sales

```text
Lead ≠ Client Case / Deal ≠ Property Match
inbound demand signal ≠ managed sales process ≠ demand↔supply association
```

Sales currently represents some canonical entities through application/persistence services instead of one dedicated aggregate class. `application-persisted` is an explicit representation fact, not a downgrade of business ownership.

### Diagnostic

`DiagnosticPack` is versioned: revision creates a new draft version instead of mutating a published version. `DiagnosticSession` pins an exact pack version and has its own independent lifecycle.

## State contract

1. Entity identity and entity state are separate concepts.
2. State vocabularies are scoped to their owning entity and Domain; COS has no universal business `status` enum.
3. Registry state values must match the current source enum or public string constants exactly; CI fails on drift.
4. `terminal_values` are declared only when source semantics explicitly prohibit further lifecycle progression.
5. `closed_values` describe business closure semantics without claiming the state is technically irreversible.
6. Process touchpoints must resolve to canonical Process Registry steps owned by the same Domain as the entity.

## Authority and freshness

- Runtime/domain code on `main` remains the factual authority.
- This registry records the main revision last reviewed for these facts: `25d256a144cdb6098f895e21df75d3acab405132`.
- Documentation CI verifies the structured registry and generated projection against the current documentation checkout; it does not silently claim cross-branch freshness.
- Automated inter-branch freshness enforcement belongs to documentation governance and must be explicit rather than inferred from a green build.
- Generated Markdown is a projection, never executable authority.
