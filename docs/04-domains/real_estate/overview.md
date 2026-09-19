---
title: Огляд домену Real Estate
description: Runtime V0.2 для брокерського lifecycle поверх канонічного Property registry.
status: active
updated: 2026-09-18
kind: domain
contract: domain-v1
---

# Огляд домену Real Estate

Real Estate `0.2.0` є orchestration Domain для брокерських процесів. Він не створює другого реєстру нерухомості і не дублює Property inventory.

## Канонічний сценарій Wave 9

```text
Sales Opportunity
      ↓
Property Match
      ↓
Offer
      ↓
Viewing
      ↓
Reservation
```

`Property` залишається source of truth для asset registry, identity, inventory, listing/publication, catalog та presentation. `Sales` залишається source of truth для opportunity. RealEstate володіє тільки брокерським зв’язком і переходами між ними.

## Runtime

```text
id: real_estate
version: 0.2.0
dependencies: property, sales
runtime: realEstateDomainModule
persistence: tn_real_estate_cases / offers / showings
transport: Symfony /api/v1
events: real_estate.*
```

## Межі

RealEstate читає Property тільки через `PropertyReferencePort`, а reservation виконує через `PropertyInventoryCommandInterface`. Sales opportunity перевіряється через `SalesOpportunityReferenceInterface`.

Прямі SQL-доступи RealEstate Application layer до Property або Sales таблиць заборонені. Reservation залишається транзакційною операцією Property Domain з блокуванням Inventory row для захисту від конкурентного подвійного бронювання.

## API

```text
POST /api/v1/sales/opportunities/{id}/property-matches
GET  /api/v1/real-estate/cases/{id}
POST /api/v1/real-estate/cases/{id}/offers
POST /api/v1/real-estate/cases/{id}/viewings
POST /api/v1/real-estate/cases/{id}/reservation
```
