---
title: Міждоменна топологія бізнес-процесів
description: Згенерована topology канонічних переходів бізнес-процесів через межі COS Domains, включно з required contracts, target capabilities та evidence strength.
status: generated
updated: 2026-09-16
kind: reference
contract: reference-v1
generated: true
---

# Міждоменна топологія бізнес-процесів

Згенеровано з cross-domain кроків Process Registry schema v5+ і каталогу runtime evidence поточного checkout. Не редагуйте цю сторінку вручну.

Це представлення показує, **де бізнес-процес залишає свій Domain-власник, який канонічний contract дозволяє цей перехід, яка target capability використовується і як contract підтверджено evidence**.

## Підсумок

- **Перевірено канонічних процесів:** 5
- **Cross-domain процесів:** 2
- **Cross-domain кроків:** 4
- **Унікальних меж:** 4
- **Domains-учасників:** 3

## Топологія доменів

```mermaid
flowchart LR
    domain_property["property"]
    domain_real_estate["real_estate"]
    domain_sales["sales"]
    domain_real_estate -->|PropertyInventoryCommandInterface · property.inventory · 1 крок| domain_property
    domain_real_estate -->|PropertyReferencePort · property.reference · 1 крок| domain_property
    domain_real_estate -->|SalesOpportunityReferenceInterface · sales.workspace.use · 1 крок| domain_sales
    domain_sales -->|PropertyReferencePort · property.reference · 1 крок| domain_property
```

## Переходи процесів

| Процес | Крок | З Domain | До Domain | Contract | Target capability | Evidence |
| --- | --- | --- | --- | --- | --- | --- |
| [Opportunity → Property Reservation](../02-workflows/real-estate-opportunity-to-reservation.md) | `reserve-inventory` · Reserve Property Inventory | `real_estate` | `property` | `Domains\Property\Application\Contract\PropertyInventoryCommandInterface` | `property.inventory` | `runtime` |
| [Opportunity → Property Reservation](../02-workflows/real-estate-opportunity-to-reservation.md) | `resolve-property` · Resolve canonical Property and Inventory | `real_estate` | `property` | `Domains\Property\Contract\PropertyReferencePort` | `property.reference` | `runtime` |
| [Opportunity → Property Reservation](../02-workflows/real-estate-opportunity-to-reservation.md) | `validate-opportunity` · Validate Sales opportunity | `real_estate` | `sales` | `Domains\RealEstate\Application\Contract\SalesOpportunityReferenceInterface` | `sales.workspace.use` | `runtime` |
| [Sales Request → Property Match](../02-workflows/sales-request-to-property-match.md) | `resolve-property` · Resolve canonical Property presentation | `sales` | `property` | `Domains\Property\Contract\PropertyReferencePort` | `property.reference` | `runtime` |

## Агрегація меж

| Межа | Contract | Capability | Процесів | Кроків | Evidence |
| --- | --- | --- | ---: | ---: | --- |
| `real_estate → property` | `Domains\Property\Application\Contract\PropertyInventoryCommandInterface` | `property.inventory` | 1 | 1 | `runtime` |
| `real_estate → property` | `Domains\Property\Contract\PropertyReferencePort` | `property.reference` | 1 | 1 | `runtime` |
| `real_estate → sales` | `Domains\RealEstate\Application\Contract\SalesOpportunityReferenceInterface` | `sales.workspace.use` | 1 | 1 | `runtime` |
| `sales → property` | `Domains\Property\Contract\PropertyReferencePort` | `property.reference` | 1 | 1 | `runtime` |

## Авторитетність і обмеження

- Process Registry володіє topology процесу і Domain, призначеним кожному кроку.
- Декларації module `cross_domain_contracts` володіють authority синхронних Domain boundaries.
- Runtime Evidence Resolver перевіряє, що Domain-власник процесу декларує `requires` contract до target Domain.
- Target capability залишається у власності target Domain; cross-domain перехід ніколи не створює shared state ownership.
- Цей довідник агрегує лише канонічні змодельовані переходи. Він не виводить hidden dependencies із SQL, imports, service locators або HTTP calls.
- Evidence strength описує структурне evidence поточного checkout. Це не observed production execution trace.
- Interactive documentation view є projection семантики Process Registry. Ця generated page є evidence-enriched reference.
