---
title: Opportunity → Property Reservation
description: Канонічний brokerage-процес Wave 9: від Sales opportunity через Property Match, Offer і Viewing до бронювання Inventory у Property Domain.
status: active
updated: 2026-09-18
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: real_estate.opportunity-to-reservation
---

# Opportunity → Property Reservation

## Бізнес-мета

Провести конкретну Sales opportunity від підбору канонічного Property до бронювання Inventory без shared ownership між Sales, RealEstate та Property.

RealEstate володіє brokerage case, match, offer і viewing. Sales залишається власником opportunity. Property залишається власником Asset, Inventory і Reservation.

## Учасники

- Sales opportunity boundary;
- RealEstate broker;
- Property boundary.

## Тригер

Менеджер має активну Sales opportunity і вибирає Property, який потрібно провести через brokerage lifecycle до reservation.

## Межі доменів

```text
Sales Opportunity
      ↓  SalesOpportunityReferenceInterface
RealEstate
      ↓  PropertyReferencePort
Property facts
      ↓
RealEstate Match → Offer → Viewing
      ↓  PropertyInventoryCommandInterface
Property Reservation
      ↓
RealEstate reservation outcome
```

RealEstate не читає Sales або Property storage напряму. Обидва переходи проходять через задекларовані `requires` contracts.

## Процес

<ProcessDiagram process-id="real_estate.opportunity-to-reservation" />

## Представлення відповідальності

<ProcessDiagram process-id="real_estate.opportunity-to-reservation" view="ownership" direction="LR" />

## Представлення доменів

<ProcessDiagram process-id="real_estate.opportunity-to-reservation" view="domain" direction="LR" />

## Представлення можливостей

<ProcessDiagram process-id="real_estate.opportunity-to-reservation" view="capability" direction="LR" />

## Шлях виконання

```text
MatchPropertyCommand
  ↓
RealEstateWorkflowService::match()
  ↓
SalesOpportunityReferenceInterface
  ↓
PropertyReferencePort
  ↓
BrokerageProcess: matched
  ↓
CreatePropertyOfferCommand
  ↓
BrokerageProcess: offered
  ↓
SchedulePropertyViewingCommand
  ↓
BrokerageProcess: viewing
  ↓
ReserveMatchedPropertyCommand
  ↓
PropertyInventoryCommandInterface::reserve()
  ↓
Property Inventory reservation
  ↓
BrokerageProcess: reserved
```

## Інваріанти

1. Sales opportunity перевіряється через RealEstate anti-corruption port.
2. Property facts читаються тільки через `PropertyReferencePort`.
3. Reservation виконує Property Domain через `PropertyInventoryCommandInterface`.
4. RealEstate ніколи не змінює Property tables напряму.
5. RealEstate ніколи не читає Sales tables напряму.
6. Усі write operations tenant-scoped, transactional, idempotent і audited.
7. Inventory reservation серіалізується row lock у Property runtime.
8. Повтор того самого idempotency key з іншим payload є conflict.
9. `reserved` є terminal brokerage state для Wave 9.

## Карта коду

```text
symfony/src/Application/RealEstate/Command/*
app/Domains/RealEstate/Application/Service/RealEstateWorkflowService.php
app/Domains/RealEstate/Application/Contract/SalesOpportunityReferenceInterface.php
app/Domains/RealEstate/Infrastructure/Sales/SalesOpportunityReferenceAdapter.php
app/Domains/Property/Contract/PropertyReferencePort.php
app/Domains/Property/Application/Contract/PropertyInventoryCommandInterface.php
app/Domains/Property/Application/Service/CanonicalPropertyInventoryCommands.php
```
