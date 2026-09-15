---
title: Property Domain Overview
description: Canonical real-estate asset runtime, Inventory, Listing/Publication, history, intelligence and external interoperability.
status: active
updated: 2026-09-15
kind: domain
---

# Property Domain Overview

Property — canonical owner фізичного real-estate asset state у COS.

## Core separation

```text
Property Asset = що фізично існує
Inventory Item = як organization комерційно працює з активом
Listing        = як пропозиція представлена ринку
Publication    = де Listing опублікований
Sales          = попит, pipeline і deal process
CRM            = people/relationship context
```

Квартира не стає фізично `SOLD`; `SOLD` є комерційним станом Inventory.

## Read this domain

<div class="cos-system-map">
  <div class="cos-map-layer">
    <div class="cos-map-title">Property knowledge path</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="./domain-model.html"><strong>Domain Model</strong><span>Asset, Inventory, Listing, Publication, structure та ownership.</span></a>
      <a class="cos-map-node" href="./lifecycle-and-runtime.html"><strong>Lifecycle & Runtime</strong><span>Submission, identity, commercial lifecycle та V0.12 cutover.</span></a>
      <a class="cos-map-node" href="./contracts-and-code-map.html"><strong>Contracts & Code</strong><span>Reference ports, network boundary, compatibility та code map.</span></a>
    </div>
  </div>
</div>

## Current scope

Property `0.12.0` охоплює canonical asset registry/structure, identity/provenance, Inventory, Listing/Publication, append-only history, analytics/intelligence, external Property Network/RESO boundary та authoritative canonical runtime writes.

## Runtime manifest

```text
id: property
version: 0.12.0
schema: 0.12.0
kernel: >=0.11.0 <0.12.0
enabled_by_default: true
```

## Authoritative mutation rule

```text
Web / API / Spatial
        ↓
PropertyCanonicalRuntimeService
        ↓
PropertyAsset / InventoryItem / Listing / Publication
        ↓
Domain Events → Kernel EventBus / Outbox
        ↓
Compatibility Projection
        ↓
tn_properties
```

`tn_properties` не є canonical source of Property state.

## Related workflow and reference

- [Property Submission → Publication](../../02-workflows/property-submission-to-publication.md)
- [Module & Capabilities](../../12-reference/module-capabilities.md)
- [Events](../../12-reference/event-types.md)
- [Commands](../../12-reference/commands.md)
- [Routes](../../12-reference/module-routes.md)
