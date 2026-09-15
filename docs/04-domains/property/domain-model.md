---
title: Property Domain Model
description: Canonical Property Asset, Inventory, Listing and Publication model with ownership boundaries.
status: active
updated: 2026-09-15
kind: domain
---

# Property Domain Model

Property є canonical registry фізичних real-estate assets та власником Property-specific commercial projections.

## Fundamental separation

```text
PROPERTY ≠ INVENTORY ≠ LISTING ≠ PUBLICATION
```

### Property Asset

Відповідає на питання **що фізично існує**: stable identity, type, location, lifecycle, structure, typed physical specs, relations, media, provenance та canonical history.

### Inventory Item

Відповідає **що конкретна organization може комерціалізувати зараз**: transaction type, asking price, availability, reservation/commercial state та terms.

### Listing

Відповідає **як Inventory Item представлено ринку**: marketing title/description, presentation price, SEO/public features і media references.

### Publication

Відповідає **де Listing опублікований**: channel, external id/url, publication state та sync lifecycle.

## Structure graph

```text
Development
 └─ Building
     └─ Entrance / Section
         └─ Floor
             └─ Unit
```

Також природно існують `LandPlot`, `House`, `CommercialUnit`, parking/storage та інші typed assets. Не кожен asset зобов'язаний мати всю ієрархію.

## Observation vs truth

`PropertySubmission`, `PropertySource`, `ExternalReference`, `DataProvenance` і `PropertyNetworkRecord` описують intake та походження інформації. Вони не є другим canonical Property registry.

`PropertyIntelligenceSnapshot` є derived inference, прив'язаним до facts/evidence. Intelligence не переписує canonical Asset або Inventory facts.

## Ownership boundary

Property не володіє people/relationships, Sales pipeline/demand lifecycle, payments, legal documents або provider secrets. Інші Domains request/reference/react через explicit contracts та Events.

## Read next

- [Lifecycle & Runtime](./lifecycle-and-runtime.md)
- [Contracts & Code Map](./contracts-and-code-map.md)
- [Property Submission → Publication](../../02-workflows/property-submission-to-publication.md)
