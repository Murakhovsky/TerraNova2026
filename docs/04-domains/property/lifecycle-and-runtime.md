---
title: Property Lifecycle & Runtime
description: Canonical Property intake, identity, Inventory, Listing, Publication and V0.12 runtime lifecycle.
status: active
updated: 2026-09-15
kind: domain
---

# Property Lifecycle & Runtime

## End-to-end lifecycle

```text
External observation / Submission
        ↓
Validation + identity resolution
        ↓
CREATE / MERGE / REVIEW
        ↓
Canonical PropertyAsset
        ↓
InventoryItem
        ↓
Listing
        ↓
Publication
        ↓
Channel / External Network
```

`MERGE` означає resolve incoming observation до вже існуючого canonical asset. Це не destructive merge двох canonical assets. Ambiguous match переходить у explicit review з audit trail.

## Inventory lifecycle

```text
AVAILABLE
→ RESERVED / ON_HOLD / UNDER_OFFER
→ SOLD / RENTED
→ OFF_MARKET / WITHDRAWN
```

Це lifecycle комерційної пропозиції organization, а не фізичного Property Asset. Продана квартира продовжує існувати як квартира.

## V0.12 authoritative mutation path

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

`tn_properties` є transitional projection/read surface. Нові Asset, Inventory, Listing, Publication та Spatial presentation mutations повинні входити через canonical runtime.

## Network lifecycle

External Network sync зберігає durable run/record ledger, idempotency metadata та source identity. Incoming UPSERT проходить через `PropertySubmission`; incoming DELETE стає tombstone і не видаляє canonical truth автоматично.

## History and intelligence

Property/Inventory/Publication changes мають append-only history contracts. Analytics та Intelligence будуються поверх canonical facts і не отримують права тихо змінювати ці facts.

## Runtime reference

- [Execution Lifecycle](../../05-runtime/execution-lifecycle.md)
- [Events & Outbox](../../05-runtime/events-and-outbox.md)
- [Event Types](../../12-reference/event-types.md)
- [Module Capabilities](../../12-reference/module-capabilities.md)
