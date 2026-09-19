---
title: Service Request → Ticket Close
description: "Канонічний Service Wave 11 процес від створення звернення через Ticket, Assignment та SLA до Resolution і Close."
status: active
updated: 2026-09-19
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: service.request-to-close
---

# Service Request → Ticket Close

## Бізнес-мета

Перетворити сервісне звернення на керований операційний lifecycle з явним власником, SLA, ескалаціями, зафіксованим результатом і контрольованим закриттям.

Service Domain володіє `ServiceCase`, `Request`, `Ticket`, історією Assignment, SLA snapshot, Escalation та Resolution. HTTP/Symfony є delivery boundary і не володіє бізнес-станом.

## Учасники

- service manager;
- service assignee;
- requester.

## Тригер

Користувач або інтеграція створює сервісне звернення, яке потребує контрольованого виконання, відповідального виконавця та SLA.

## Межа домену

```text
Service Request
      ↓
ServiceCase + Request
      ↓
Ticket
      ↓
Assignment + SLA
      ├─ normal ─────────→ Resolution
      └─ risk / breach → Escalation → Resolution
                                  ↓
                                Close
```

Усі Service mutations проходять через Application commands і `ServiceWorkflowService`. HTTP controller не працює з MySQL напряму, а persistence залишається за Service repository adapter.

## Процес

<ProcessDiagram process-id="service.request-to-close" />

Основний потік генерується з Process Registry. `process_state: as-is` означає, що схема відображає поточний виконуваний runtime Wave 11.

## Представлення відповідальності

<ProcessDiagram process-id="service.request-to-close" view="ownership" direction="LR" />

Service manager володіє створенням, assignment, SLA, escalation та close. Service assignee відповідає за resolution.

## Представлення доменів

<ProcessDiagram process-id="service.request-to-close" view="domain" direction="LR" />

Wave 11 є внутрішньодоменним Service-процесом. Cross-domain інтеграції додаються через contracts, а не прямі звернення до чужих таблиць.

## Представлення можливостей

<ProcessDiagram process-id="service.request-to-close" view="capability" direction="LR" />

Process Registry пов'язує кожен крок із конкретною Service capability: request, ticket, assignment, SLA, escalation або resolution.

## Шлях виконання

```text
CreateServiceRequestCommand
  ↓
ServiceWorkflowService::createRequest()
  ↓
ServiceCase + Request
  ↓
CreateServiceTicketCommand
  ↓
ServiceWorkflowService::createTicket()
  ↓
AssignServiceTicketCommand
  ↓
SetServiceSlaCommand
  ├─ normal ─────────→ ResolveServiceTicketCommand
  └─ risk / breach → EscalateServiceTicketCommand
                          ↓
                    ResolveServiceTicketCommand
                          ↓
                    CloseServiceTicketCommand
                          ↓
             Request / ServiceCase auto-close
```

## Кроки

1. **Create Request** атомарно створює `ServiceCase` і `Request`.
2. **Create Ticket** відкриває операційний Ticket для Request.
3. **Assign Ticket** створює історичний Assignment і оновлює current assignee.
4. **Set SLA** фіксує immutable SLA snapshot та обчислені response/resolution deadlines.
5. **Escalate** збільшує escalation level і зберігає причину.
6. **Resolve** створює Resolution і переводить Ticket у `resolved`.
7. **Close** дозволений лише після Resolution. Після останнього закритого Ticket автоматично закриваються Request і ServiceCase.

## Інваріанти

1. Усі записи tenant-scoped через `organization_id`.
2. Кожна write operation вимагає валідний idempotency key; повтор з іншим payload є conflict.
3. Ticket lifecycle не дозволяє мутації після terminal state.
4. Ticket не можна закрити до Resolution.
5. Конкурентні lifecycle mutations серіалізуються row lock-ом.
6. Створення Ticket і автоматичне закриття Request серіалізуються lock-ом Request.
7. Mutation, lifecycle Event та Audit виконуються в одному transaction boundary.
8. Symfony controller використовує CommandBus/QueryBus і не володіє persistence.
9. Tenant/module/permission/CSRF перевіряються на delivery boundary.

## Надійність

Service Wave 11 має exact idempotency receipts, transaction handling, row locking, lifecycle Events, Audit і correlation ID. Історичні Assignment, SLA, Escalation та Resolution не підміняються одним mutable JSON-полем, бо людство вже достатньо страждало від таких «швидких» рішень.

Канонічна машиночитана Process definition: `resources/processes/service-request-to-close.json`.

## Карта коду

```text
symfony/src/Http/Api/V1/Controller/ServiceController.php
symfony/src/Application/Service/Command/*
symfony/src/Application/Service/Query/*
app/Domains/Service/Application/Service/ServiceWorkflowService.php
app/Domains/Service/Domain/ServiceTicketLifecycle.php
app/Domains/Service/Application/Contract/ServiceRepositoryInterface.php
app/Domains/Service/Infrastructure/Persistence/MySql/MysqlServiceRepository.php
app/Domains/Service/Infrastructure/Persistence/MySql/MysqlServiceMutationReceipt.php
app/migrations/20260919_000064_service_wave11_cutover.sql
```
