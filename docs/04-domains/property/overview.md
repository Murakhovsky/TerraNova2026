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
Listing        = як пропозиція представлена/публікується
Sales          = попит, pipeline і deal process
CRM            = people/relationship context
```

Квартира не стає фізично `SOLD`; `SOLD` є комерційним станом Inventory.

## Asset model

Property `0.12.0` охоплює:

- canonical asset registry і structure graph;
- asset kind/lifecycle/relations;
- location/address/geo model;
- identity resolution, external references, provenance і verification;
- operational identity `CREATE / MERGE / REVIEW` workflow with review audit;
- canonical aliases for legacy property identifiers;
- Inventory lifecycle, reservations, transaction type і price state;
- Listing/Publication lifecycle та media presentation state;
- append-only history/event contracts;
- market analytics and evidence-linked Property Intelligence;
- external Property Network intake/export/sync boundary;
- RESO Web API connector boundary;
- canonical runtime writes for Asset, Inventory, Listing and Publication;
- canonical REST mutation surface;
- one-way compatibility projection into legacy `tn_properties` for transitional read surfaces.

## Runtime manifest

```text
id: property
version: 0.12.0
schema: 0.12.0
kernel: >=0.11.0 <0.12.0
enabled_by_default: true
```

AS-IS contributions:

- runtime module service `propertyDomainModule`;
- canonical runtime service `propertyCanonicalRuntime`;
- API route contributor `propertyRouteContributor`;
- configuration provisioner `propertyModuleConfigurationProvisioner`;
- Web navigation contribution;
- Property migrations through `V0.12.0`;
- explicit capability `property.runtime.canonical`.

## V0.12 canonical runtime rule

The authoritative mutation direction is now:

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
tn_properties (legacy read surface)
```

`tn_properties` is **not** a canonical source of Property state. New Asset, commercial price/status, Listing publication and 3D-tour presentation mutations must enter through the canonical runtime first.

Legacy Property management read/group/media surfaces remain transitional compatibility code where a canonical replacement has not yet been introduced. They may not own Asset/Inventory/Listing business truth. The compatibility projection is the explicit boundary allowed to materialize canonical state into `tn_properties`.

## V0.11 hardening rule retained

Identity merge means resolving an incoming observation/submission into one existing canonical asset. It does **not** destructively merge two canonical assets. Ambiguous matches enter explicit review; review decisions are audited.

The RESO adapter implements the V0.10 `PropertyNetworkConnectorInterface`; provider authentication, endpoint details and secrets remain outside Property behind `configuration_reference` and the injected transport port.

## Cross-domain rule

Sales може читати Property через explicit Property reference/read contracts, але не має володіти canonical Property state. Property, у свою чергу, не володіє deal pipeline або CRM relationships.

## Generated facts

- [Module & Capabilities](../../12-reference/module-capabilities.md)
- [Events](../../12-reference/event-types.md)
- [Commands](../../12-reference/commands.md)
- [Routes](../../12-reference/module-routes.md)
