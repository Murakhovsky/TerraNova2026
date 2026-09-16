---
title: Типи подій
description: Згенерований довідник типів runtime-подій, якими володіють модулі.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Типи подій

> Джерело істини: явні каталоги подій у поточному checkout.

## Підсумок

| Модуль | Типів подій |
| --- | ---: |
| `property` | 17 |
| `sales` | 16 |

## Каталог

| Модуль | Тип події | Символ | Джерело | Runtime-власник |
| --- | --- | --- | --- | --- |
| `property` | `property.asset.lifecycle_changed` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.asset.location_changed` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.asset.registered` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.asset.type_changed` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.created` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.inventory.available` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.inventory.created` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.inventory.price_changed` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.inventory.released` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.inventory.reserved` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.inventory.status_changed` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.lifecycle_changed` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.listing.created` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.listing.hidden` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.listing.published` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.relation_changed` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `property` | `property.structure_changed` | `Domains\Property\Automation\Event\PropertyEventType::values()` | `app/Domains/Property/Automation/Event/PropertyEventType.php` | `app/Domains/Property/Bootstrap/PropertyDomainModule.php` |
| `sales` | `sales.deal.lost` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.deal.owner_assigned` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.deal.stuck` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.deal.won` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.followup.completed` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.followup.created` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.followup.missed` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.lead.contacted` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.lead.disqualified` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.lead.qualified` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.meeting.completed` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.message.received` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.message.sent` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.no_activity_detected` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.task.completed` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.task.created` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
