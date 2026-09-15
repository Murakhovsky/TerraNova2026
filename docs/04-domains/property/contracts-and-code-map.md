---
title: Property Contracts & Code Map
description: Cross-domain ports, network adapters, canonical runtime boundaries and implementation map for Property.
status: active
updated: 2026-09-15
kind: domain
---

# Property Contracts & Code Map

## Main dependency rule

> A Domain owns its state. Other Domains may request, reference and react, but never modify that state directly.

Sales therefore consumes Property through explicit Property reference/read contracts. It does not query or mutate canonical Property tables as part of Sales business logic.

## Key boundaries

### Property reference boundary

`PropertyReferencePort` exposes stable Property references/presentations to consumers without transferring ownership of Property state.

### External Property Network

`PropertyNetworkConnectorInterface` isolates MLS, developer APIs, portal feeds and other providers from the Property core. Provider credentials/configuration remain outside Property behind configuration references and injected transport.

### Location reference

Reference location materialization remains owned by Reference and is accessed through `LocationReferenceInterface`. Property must not write reference-location storage directly.

### Canonical runtime

`PropertyCanonicalRuntimeService` is the authoritative operational write boundary for Asset, Inventory, Listing and Publication in V0.12.

## Compatibility rule

Legacy `tn_properties`, historical presentation code and Telegram Realty/Object models may serve isolated compatibility reads/projections. Вони не можуть визначати canonical Asset/Inventory/Listing truth.

## Code map

```text
app/Domains/Property/
├─ Model / domain vocabulary
├─ Application / use cases and services
├─ Infrastructure / persistence, network, read models, adapters
├─ Bootstrap / module contribution
└─ module.php

app/Bootstrap/PropertyServices.php
app/Bootstrap/PropertyNetworkServices.php
app/Interfaces/Api/Controller/PropertyCanonicalController.php
```

## Exact executable facts

- [Module & Capabilities](../../12-reference/module-capabilities.md)
- [Application Use Cases](../../12-reference/application-use-cases.md)
- [Commands](../../12-reference/commands.md)
- [Events](../../12-reference/event-types.md)
- [Routes](../../12-reference/module-routes.md)
