---
title: Event Types
description: Generated reference of module-owned runtime event types.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Event Types

> Джерела істини: explicit event catalogues та `TYPE` constants, які формують `EventOwningModuleInterface::eventTypes()`.

Ця сторінка описує **runtime event ownership vocabulary**. Вона не намагається вгадувати події через regex-сканування всього PHP-коду і не змішує event types з consumer subscriptions або фактичними outbox records.

## Summary

| Module | Event types | Runtime owner source |
| --- | ---: | --- |
| `sales` | 25 | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |

## Catalogue

| Module | Event type | Declaration | Symbol | Source | Runtime owner |
| --- | --- | --- | --- | --- | --- |
| `sales` | `sales.action_outcome.measured` | `event-class` | `Domains\Sales\Automation\Event\ActionOutcomeMeasured::TYPE` | `app/Domains/Sales/Automation/Event/ActionOutcomeMeasured.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.call.completed` | `event-class` | `Domains\Sales\Automation\Event\CallCompleted::TYPE` | `app/Domains/Sales/Automation/Event/CallCompleted.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.client_case.changed` | `event-class` | `Domains\Sales\Automation\Event\ClientCaseChanged::TYPE` | `app/Domains/Sales/Automation/Event/ClientCaseChanged.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.client_case.created` | `event-class` | `Domains\Sales\Automation\Event\ClientCaseCreated::TYPE` | `app/Domains/Sales/Automation/Event/ClientCaseCreated.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.deal.created` | `event-class` | `Domains\Sales\Automation\Event\DealCreated::TYPE` | `app/Domains/Sales/Automation/Event/DealCreated.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.deal.lost` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.deal.owner_assigned` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.deal.stage_changed` | `event-class` | `Domains\Sales\Automation\Event\DealStageChanged::TYPE` | `app/Domains/Sales/Automation/Event/DealStageChanged.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.deal.stuck` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.deal.won` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.followup.completed` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.followup.created` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.followup.missed` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.followup.overdue` | `event-class` | `Domains\Sales\Automation\Event\FollowupOverdue::TYPE` | `app/Domains/Sales/Automation/Event/FollowupOverdue.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.lead.changed` | `event-class` | `Domains\Sales\Automation\Event\LeadChanged::TYPE` | `app/Domains/Sales/Automation/Event/LeadChanged.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.lead.contacted` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.lead.created` | `event-class` | `Domains\Sales\Automation\Event\LeadCreated::TYPE` | `app/Domains/Sales/Automation/Event/LeadCreated.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.lead.disqualified` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.lead.qualified` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.meeting.completed` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.message.received` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.message.sent` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.no_activity_detected` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.task.completed` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |
| `sales` | `sales.task.created` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` | `app/Domains/Sales/Bootstrap/SalesDomainModule.php` |

## Explicit source registry

Documentation tooling підключає event authority явно. Це дешевше, прозоріше і надійніше, ніж змушувати генератор інтерпретувати довільний PHP як археолог.

| Module | Kind | Symbol | Source |
| --- | --- | --- | --- |
| `sales` | `catalogue` | `Domains\Sales\Automation\Event\SalesEventType::all()` | `app/Domains/Sales/Automation/Event/SalesEventType.php` |
| `sales` | `event-class` | `Domains\Sales\Automation\Event\ActionOutcomeMeasured::TYPE` | `app/Domains/Sales/Automation/Event/ActionOutcomeMeasured.php` |
| `sales` | `event-class` | `Domains\Sales\Automation\Event\CallCompleted::TYPE` | `app/Domains/Sales/Automation/Event/CallCompleted.php` |
| `sales` | `event-class` | `Domains\Sales\Automation\Event\ClientCaseChanged::TYPE` | `app/Domains/Sales/Automation/Event/ClientCaseChanged.php` |
| `sales` | `event-class` | `Domains\Sales\Automation\Event\ClientCaseCreated::TYPE` | `app/Domains/Sales/Automation/Event/ClientCaseCreated.php` |
| `sales` | `event-class` | `Domains\Sales\Automation\Event\DealCreated::TYPE` | `app/Domains/Sales/Automation/Event/DealCreated.php` |
| `sales` | `event-class` | `Domains\Sales\Automation\Event\DealStageChanged::TYPE` | `app/Domains/Sales/Automation/Event/DealStageChanged.php` |
| `sales` | `event-class` | `Domains\Sales\Automation\Event\FollowupOverdue::TYPE` | `app/Domains/Sales/Automation/Event/FollowupOverdue.php` |
| `sales` | `event-class` | `Domains\Sales\Automation\Event\LeadChanged::TYPE` | `app/Domains/Sales/Automation/Event/LeadChanged.php` |
| `sales` | `event-class` | `Domains\Sales\Automation\Event\LeadCreated::TYPE` | `app/Domains/Sales/Automation/Event/LeadCreated.php` |

## Scope

- event type тут означає canonical string, ownership якого реєструє domain module у Kernel;
- consumer subscriptions документуються окремо через extension points;
- persistence/outbox records є runtime data, а не частиною static event catalogue;
- `npm run docs:generate:check` ловить зміну значення зареєстрованого catalogue або `TYPE` constant без оновлення generated reference.
