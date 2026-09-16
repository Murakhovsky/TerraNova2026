---
title: Sales Request → Property Match
description: Cross-domain Sales workflow, який перетворює вхідну заявку на Client Case і читає факти Property через канонічну межу.
status: active
updated: 2026-09-16
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: sales.request-to-property-match
---

# Sales Request → Property Match

## Бізнес-мета

Перетворити конкретну вхідну заявку на керований Client Case без перенесення ownership Property у Sales. Sales зберігає relationship і match, а факти про Asset, Inventory та Listing читає через канонічний Property contract.

## Учасники

- менеджер продажу;
- Sales Application;
- Property read boundary.

## Тригер

Sales operator запускає створення Client Case з уже отриманої inbound request, яка може містити `property_id`.

## Межа доменів

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

`Sales → Property` є реальним cross-domain переходом, але не shared ownership. Sales module декларує `PropertyReferencePort` як `requires` contract, а крок `resolve-property` виконується в Domain `property` через capability `property.reference`.

Sales не читає Property tables напряму й не перетворює Property snapshot на власний canonical asset.

## Процес

<ProcessDiagram process-id="sales.request-to-property-match" />

Основний потік генерується з Process Registry. `process_state: as-is` означає, що кроки відповідають поточному коду.

Derived verification для процесу є `source-verified`: критичні кроки мають evidence з поточного checkout, а cross-domain `PropertyReferencePort` має runtime-strength evidence з module contract registry.

## Представлення відповідальності

<ProcessDiagram process-id="sales.request-to-property-match" view="ownership" direction="LR" />

`Property read boundary` відповідає лише за надання канонічних Property facts. Client Case і Property Match залишаються відповідальністю Sales.

## Представлення доменів

<ProcessDiagram process-id="sales.request-to-property-match" view="domain" direction="LR" />

Проєкція показує реальний Domain hop: Sales створює case, переходить через підтверджений `requires` contract у Property для resolution канонічного reference, а потім повертається в Sales для запису match і activity/events.

## Представлення можливостей

<ProcessDiagram process-id="sales.request-to-property-match" view="capability" direction="LR" />

Property step має `property.reference`. Sales steps поки мають explicit capability gaps, бо capability vocabulary модуля переважно описує workspace/admin authority, а не semantic operations цього процесу. Такі gaps мають matching Capability Debt items і не підміняються `sales.workspace.use`.

## Шлях виконання

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

## Точки рішень

- чи існує inbound request;
- чи вона вже прив’язана до Client Case;
- чи можна знайти або створити Person;
- чи request містить `property_id`;
- чи Property boundary повернув канонічне представлення;
- чи треба створити або оновити Sales Property Match.

Якщо Property не знайдено, Client Case може існувати без вигаданого match.

## Власність даних

`tn_client_case_property_matches` є Sales-owned relationship state. Він посилається на Property reference, але не є копією Property Asset або Inventory.

Property facts для UI/read models також збагачуються через `SalesPropertyReference`, зокрема в `MysqlClientCaseReadModel::propertyMatches()`.

## Шляхи помилок

- request не знайдено → `request_not_found`;
- request уже має case → повертається existing case;
- помилка прив’язки request → case → transaction failure;
- Property reference відсутній або не resolve-иться → case створюється без Property Match;
- transaction failure → Sales state не повинен залишатися частково записаним.

## Інваріанти

1. Sales володіє demand, case та match relationship.
2. Property володіє Asset, Inventory та Listing facts.
3. Sales не мутує Property через цей процес.
4. Property resolution проходить через `PropertyReferencePort`.
5. Cross-domain step легальний через підтверджений `requires` contract.
6. `resolve-property` використовує `property.reference`, а не Sales capability gap.
7. Tenant boundary передається як `organizationId`.
8. Match записується лише після успішного Property resolution.
9. Capability gap не маскується широким workspace permission.

## Карта коду

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
