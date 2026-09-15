---
title: Sales Request → Property Match
description: Runtime-backed cross-domain Sales workflow that converts an inbound request into a Client Case and resolves referenced Property facts through the canonical Property boundary.
status: active
updated: 2026-09-15
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: sales.request-to-property-match
---

# Sales Request → Property Match

## Business goal

Перетворити конкретну вхідну заявку на керований Client Case, не копіюючи ownership Property у Sales: Sales зберігає relationship/match, а факти про asset, inventory та listing читає через canonical Property contract.

## Actors

- salesperson;
- Sales application;
- Property read boundary.

## Trigger

Sales operator запускає створення Client Case із вже отриманої inbound request, яка може містити `property_id`.

## Domain boundary

```text
Sales
├── owns: Lead / inbound request
├── owns: Client Case
├── owns: Property Match
├── owns: Sales activity and Sales events
└── requires: PropertyReferencePort
          ↓
Property
└── owns: Asset / Inventory / Listing facts
```

`Sales → Property` є реальним cross-domain переходом, але не shared ownership. Sales module декларує `PropertyReferencePort` як `requires` contract, а step `resolve-property` виконується в Domain `property` через canonical capability `property.reference`.

Sales не читає Property tables напряму в application flow і не перетворює Property snapshot на власний canonical asset.

## Workflow

<ProcessDiagram process-id="sales.request-to-property-match" />

Основний flow генерується з Process Registry. `process_state: as-is` означає, що кроки відображають поточний код.

Derived verification для процесу зараз `source-verified`: усі critical steps мають current-checkout source evidence. Cross-domain `PropertyReferencePort` окремо має runtime-strength evidence з module contract registry.

## Ownership view

<ProcessDiagram process-id="sales.request-to-property-match" view="ownership" direction="LR" />

`Property read boundary` відповідає лише за надання canonical Property facts. Business process, Client Case і Property Match залишаються відповідальністю Sales.

## Domain view

<ProcessDiagram process-id="sales.request-to-property-match" view="domain" direction="LR" />

Ця derived-проєкція показує реальний Domain hop: Sales створює case, переходить через verified `requires` contract у Property для canonical reference resolution, а потім повертається в Sales для запису match і activity/events.

## Capability view

<ProcessDiagram process-id="sales.request-to-property-match" view="capability" direction="LR" />

Property step вже має canonical `property.reference`. Sales steps усе ще мають explicit capability gaps, бо current Sales module capability vocabulary описує переважно workspace/admin authority, а не semantic business operations цього flow. Тому ці gaps мають matching Capability Debt items замість фальшивого mapping на `sales.workspace.use`.

## Runtime path

```text
SalesInboundService::createCaseFromRequest()
    ↓
MysqlClientCaseCommandRepository::inboundRequest()
    ↓
createCase() + attachInboundRequest()
    ↓
SalesPropertyReference::property()
    ↓
PropertyReferencePort::getPropertyPresentation()
    ↓
MysqlPropertyReferencePort
    ↓
MysqlClientCaseCommandRepository::upsertPropertyMatch()
    ↓
Sales activity + ClientCaseCreated / LeadChanged
```

## Decision points

- inbound request існує чи вже прив'язана до Client Case;
- чи можна resolve/create Person;
- чи request містить `property_id`;
- чи Property boundary повернув canonical presentation;
- якщо Property існує, створити або оновити Sales Property Match;
- якщо Property не resolve-иться, Client Case все одно може існувати без вигаданого match.

## Data ownership

`tn_client_case_property_matches` є Sales-owned relationship state. Він посилається на Property reference, але не є копією Property Asset або Inventory.

Property facts для UI/read models також enrichment-яться через `SalesPropertyReference`, зокрема в `MysqlClientCaseReadModel::propertyMatches()`.

## Failure paths

- request не знайдена → `request_not_found`;
- request уже має case → повертається existing case;
- link request → case не вдався → transaction failure;
- Property reference відсутня або не resolve-иться → case створюється без Property Match;
- transaction failure → Sales state не повинен залишатися частково записаним.

## Invariants

1. Sales володіє demand, case та match relationship.
2. Property володіє asset/inventory/listing facts.
3. Sales не мутує Property через цей flow.
4. Property resolution проходить через `PropertyReferencePort`.
5. Cross-domain step легальний лише тому, що Sales manifest декларує verified `requires` contract до Property.
6. `resolve-property` мапиться на canonical capability `property.reference`, а не на Sales capability gap.
7. Tenant boundary передається як `organizationId`.
8. Match записується тільки після успішного Property resolution.
9. Capability gap не маскується broad workspace permission.

## Code map

```text
app/Domains/Sales/Application/Service/SalesInboundService.php
app/Domains/Sales/Application/Contract/ClientCaseCommandRepositoryInterface.php
app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php
app/Domains/Sales/Infrastructure/ReadModel/MySql/MysqlClientCaseReadModel.php
app/Domains/Sales/Infrastructure/Property/SalesPropertyReference.php
app/Domains/Sales/module.php

app/Domains/Property/Contract/PropertyReferencePort.php
app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPropertyReferencePort.php
app/Domains/Property/module.php
```
