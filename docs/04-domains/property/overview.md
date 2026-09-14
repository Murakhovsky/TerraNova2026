---
title: Property Domain Overview
description: Canonical real-estate asset registry, Inventory, Listing, analytics, intelligence і network boundary.
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

Property `0.10.0` охоплює:

- canonical asset registry і structure graph;
- asset kind/lifecycle/relations;
- location/address/geo model;
- identity resolution, external references, provenance і verification;
- Inventory lifecycle, reservations, transaction type і price state;
- Listing/Publication lifecycle та media;
- history/event contracts;
- market analytics;
- evidence-linked Property Intelligence;
- external Property Network intake/export/sync boundary.

## Runtime manifest

```text
id: property
version: 0.10.0
schema: 0.10.0
kernel: >=0.11.0 <0.12.0
enabled_by_default: true
```

AS-IS contributions:

- runtime module service `propertyDomainModule`;
- API route contributor `propertyRouteContributor`;
- configuration provisioner `propertyModuleConfigurationProvisioner`;
- Web navigation contribution;
- Property migrations through `V0.10.0`;
- explicit Property capability catalogue.

## Cross-domain rule

Sales може читати Property через explicit Property reference/read contracts, але не має володіти canonical Property state. Property, у свою чергу, не володіє deal pipeline або CRM relationships.

## Generated facts

- [Module & Capabilities](../../12-reference/module-capabilities.md)
- [Events](../../12-reference/event-types.md)
- [Commands](../../12-reference/commands.md)
- [Routes](../../12-reference/module-routes.md)
