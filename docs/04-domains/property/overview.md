---
title: Property Domain Overview
description: Canonical Property architecture through V0.10.
status: active
updated: 2026-09-14
kind: domain
---

# Property Domain Overview

Property is the COS bounded context for canonical physical real-estate identity and Property-owned projections.

## Canonical stack

```text
Asset Registry
  -> Structure / Location / Relations / Provenance
  -> Inventory
  -> Listing
  -> Publication
  -> History
  -> Analytics
  -> Intelligence

External systems
  <-> Property Network
  <-> Submission / identity resolution boundary
```

The physical asset never inherits Sales pipeline state or Listing publication state.

## Authority boundaries

Property owns Asset, Inventory, Listing/Publication, source/provenance, Property history, Property analytics read models, derived intelligence and network synchronization ledger.

CRM owns Parties/relationships. Sales owns demand, ClientCases, Deals and pipeline state. Cross-domain access uses explicit ports. Sales-to-Property direct table reads are forbidden.

Platform composition may combine Property supply with Sales demand without moving ownership of either fact set.

## Runtime contracts

Important boundaries include:

- `PropertyReferencePort`
- `PropertyAnalyticsReadModelInterface`
- `PropertyIntelligenceProviderInterface`
- `PropertyIntelligenceRepositoryInterface`
- `PropertyNetworkConnectorInterface`
- `PropertyNetworkIntakePort`
- `PropertyNetworkExportPort`
- `PropertyNetworkSyncRepositoryInterface`

## V0.10 External Property Network

Network connectors are transport adapters for MLS/developer/partner/portal/file sources. Property core stores only connector identity/configuration reference, durable sync runs and network records.

Imports are idempotent by source identity + payload hash and pass through `PropertySubmission`. External deletes are tombstones, not canonical deletes.

Exports are built from canonical `PropertyAsset + InventoryItem + Listing` data. Provider credentials remain outside Property.

## Compatibility debt

Legacy `tn_properties`, historical catalog/presentation paths and Telegram Realty/Object models are quarantined compatibility surfaces. `MysqlPropertyManagementRepository` remains oversized and should be decomposed separately rather than dragged into V0.10.

## Module status

- module: `property`
- version: `0.10.0`
- schema: `0.10.0`
- capability added in V0.10: `property.network`

Property is approaching stable-domain status, but V1.0 remains intentionally gated on real connector traffic and confirmation that canonical/cross-domain contracts do not require structural rewrites.
