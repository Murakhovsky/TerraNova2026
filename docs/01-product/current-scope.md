---
title: Current COS Scope
description: Фактичний product та architecture scope поточного main.
status: active
updated: 2026-09-14
kind: product
---

# Current COS Scope

Ця сторінка описує **AS-IS executable reality поточного `main`**.

## Executable baseline

| Component | Version | Runtime/module status |
| --- | --- | --- |
| Kernel | `0.11.8` | executable platform contract |
| Sales | `0.8.6` | full runtime module / reference domain |
| Diagnostic | `0.6.1` | installable runtime module with API routes and event consumer |
| Property | `0.11.0` | installable runtime module with canonical identity review, registry, inventory, listings, analytics, intelligence and external network interoperability |

Machine-readable metadata: [Module and Capability Reference](../12-reference/module-capabilities.md).

## Sales

Sales володіє demand lifecycle: Leads, Client Cases/Deals, pipelines, activities, follow-ups, Sales automation, governance, operational read models і Sales-facing integration contracts.

## Diagnostic

Diagnostic володіє methodology/session/evidence/evaluation/recommendation lifecycle. У `0.6.x` він має runtime module service, API route contributor, event consumer і persistent diagnostic runtime.

## Property

Property `0.11.0` hardened canonical real-estate asset model:

```text
Property Asset
├─ identity / provenance / verification
├─ CREATE / MERGE / REVIEW identity workflow + audit
├─ canonical legacy aliases
├─ structure / location / relations
├─ Inventory
├─ Listing / Publication
├─ history contracts
├─ analytics
├─ evidence-linked intelligence
└─ external Property Network + RESO adapter boundary
```

Комерційний стан відділений від фізичного Property через Inventory. Publication відділена від Inventory через Listing/Publication model. Старий management repository залишається compatibility backend, але application-facing contract уже зібраний з окремих read/group/write/workflow ports.

## Supporting areas

Identity, Content і Spatial існують як supporting bounded areas, але їхня runtime/module maturity не обов'язково дорівнює трьом installable Domains вище.

## Truth rule

```text
current main code/tests/manifests
        ↓
generated reference
        ↓
human-readable current scope
```

Roadmap/TARGET не видається за AS-IS.
