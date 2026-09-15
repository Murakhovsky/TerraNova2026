---
title: Sales Domain Model
description: Canonical vocabulary, ownership, invariants and entity relationships of the Sales bounded context.
status: active
updated: 2026-09-15
kind: domain
---

# Sales Domain Model

Sales описує **demand lifecycle**: від вхідного інтересу до керованого client case / deal, його pipeline state, activities, next action та outcome.

## Core model

```text
Person relationship
      ↓
     Lead
      ↓
ClientCase / Deal
 ├─ PipelineStage
 ├─ status / priority
 ├─ Activities
 ├─ Follow-up / next contact
 ├─ PropertyMatch → Property reference
 └─ Outcome
```

`Person`, `Lead` і `ClientCase / Deal` не є трьома назвами одного запису. Person описує сторону взаємодії, Lead — вхідний demand signal, ClientCase / Deal — керований процес продажу.

## Canonical vocabulary

Новий Sales code використовує typed vocabulary з `app/Domains/Sales/Model`, зокрема `PipelineStage`, `ClientCaseStatus`, `ClientCaseType`, `SalesPriority`, `LeadStatus`, `PropertyMatchStatus`, `SalesActivityType` і `SalesCurrency`.

External CRM values перекладаються на adapter boundary. Provider vocabulary не визначає внутрішні Sales stages, statuses або Events.

## Ownership

Sales **володіє**:

- inbound leads та qualification state;
- client cases / deals;
- pipeline, stage, status, priority та assignment;
- Sales activities, follow-up і next contact;
- property matches як demand-side references;
- Sales business events, rules, agents, actions та policies;
- canonical translation між Sales operations та зовнішнім CRM.

Sales **не володіє**:

- canonical Property Asset / Inventory / Listing;
- generic CRM relationship model поза Sales use case;
- authentication/membership;
- files/media;
- generic Queue, EventBus або LLM transport;
- provider credentials.

## Domain invariants

1. Sales state завжди tenant-scoped.
2. External provider vocabulary не стає canonical vocabulary автоматично.
3. Pipeline transition проходить Sales governance, а не controller/UI logic.
4. Cross-domain Property data читаються через explicit reference/read contracts.
5. State-changing use case узгоджує business state, Sales Event та Outbox у transaction boundary там, де Event запускає downstream processing.
6. Automation action не отримує право виконати mutation в обхід Policy.

## Read next

- [Lifecycle & Automation](./lifecycle-and-automation.md)
- [Contracts & Code Map](./contracts-and-code-map.md)
- [Sales Lead → Managed Case](../../02-workflows/sales-lead-to-managed-case.md)
- [Generated Application Use Cases](../../12-reference/application-use-cases.md)
