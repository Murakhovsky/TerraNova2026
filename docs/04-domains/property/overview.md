---
title: Property Domain Overview
description: Canonical real-estate asset registry, Inventory, Listing, analytics, intelligence, identity hardening і network interoperability.
status: active
updated: 2026-09-14
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

Property `0.11.0` охоплює:

- canonical asset registry і structure graph;
- asset kind/lifecycle/relations;
- location/address/geo model;
- identity resolution, external references, provenance і verification;
- operational identity `CREATE / MERGE / REVIEW` workflow with review audit;
- canonical aliases for legacy property identifiers without runtime fallback to `tn_properties`;
- Inventory lifecycle, reservations, transaction type і price state;
- Listing/Publication lifecycle та media;
- history/event contracts;
- market analytics;
- evidence-linked Property Intelligence;
- external Property Network intake/export/sync boundary;
- concrete transport-injected RESO Web API connector adapter for MLS/developer/partner interoperability;
- decomposed Property management ports behind a compatibility facade.

## Runtime manifest

```text
id: property
version: 0.11.0
schema: 0.11.0
kernel: >=0.11.0 <0.12.0
enabled_by_default: true
```

AS-IS contributions:

- runtime module service `propertyDomainModule`;
- API route contributor `propertyRouteContributor`;
- configuration provisioner `propertyModuleConfigurationProvisioner`;
- Web navigation contribution;
- Property migrations through `V0.11.0`;
- explicit Property capability catalogue, including `property.identity.review`.

## V0.11 hardening rule

Identity merge means resolving an incoming observation/submission into one existing canonical asset. It does **not** destructively merge two canonical assets. Ambiguous matches enter explicit review; review decisions are audited.

Legacy `tn_properties` may be read by the V0.11 one-time compatibility migration, but canonical runtime reference resolution remains on Property-owned canonical tables and `tn_property_asset_legacy_links`.

The RESO adapter implements the existing V0.10 `PropertyNetworkConnectorInterface`; provider authentication, endpoint details and secrets remain outside Property behind `configuration_reference` and the injected transport port.

## Cross-domain rule

Sales може читати Property через explicit Property reference/read contracts, але не має володіти canonical Property state. Property, у свою чергу, не володіє deal pipeline або CRM relationships.

## Generated facts

- [Module & Capabilities](../../12-reference/module-capabilities.md)
- [Events](../../12-reference/event-types.md)
- [Commands](../../12-reference/commands.md)
- [Routes](../../12-reference/module-routes.md)
