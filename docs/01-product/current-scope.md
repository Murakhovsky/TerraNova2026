---
title: Current COS Scope
description: Фактичний product та architecture scope поточного main.
status: active
updated: 2026-09-15
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
| Property | `0.12.0` | installable runtime module with canonical Asset/Inventory/Listing writes, compatibility projection, analytics, intelligence and external network interoperability |

Machine-readable metadata: [Module and Capability Reference](../12-reference/module-capabilities.md).

## Sales

Sales володіє demand lifecycle: Leads, Client Cases/Deals, pipelines, activities, follow-ups, Sales automation, governance, operational read models і Sales-facing integration contracts.

## Diagnostic

Diagnostic володіє methodology/session/evidence/evaluation/recommendation lifecycle. У `0.6.x` він має runtime module service, API route contributor, event consumer і persistent diagnostic runtime.

## Property

Property `0.12.0` є canonical runtime для real-estate assets:

```text
Property Asset
├─ identity / provenance / verification
├─ CREATE / MERGE / REVIEW identity workflow + audit
├─ canonical legacy aliases
├─ structure / location / relations
├─ Inventory
├─ Listing / Publication
├─ history + domain events
├─ analytics
├─ evidence-linked intelligence
└─ external Property Network + RESO adapter boundary
```

Основна runtime direction:

```text
Web / API / Spatial
        ↓
canonical Property runtime
        ↓
Asset / Inventory / Listing / Publication
        ↓
EventBus / history
        ↓
legacy compatibility projection
```

Комерційний стан відділений від фізичного Property через Inventory. Publication відділена від Inventory через Listing/Publication model. `tn_properties` більше не є authoritative write model для Asset/Inventory/Listing state: Web mutation paths і Spatial tour publication входять через canonical runtime, а legacy table підтримується як transitional projection/read surface. Legacy media/group/read helpers поки залишаються compatibility infrastructure там, де canonical surface ще не потрібна.

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
