---
title: Огляд домену Service
description: Виконуваний домен Service для звернень, кейсів, тікетів, SLA, призначень, ескалацій і вирішення.
status: active
updated: 2026-09-19
kind: domain
contract: domain-v1
---

# Огляд домену Service

Service `0.2.0` є активним runtime-domain COS для сервісних операцій.

## Runtime

```text
id: service
version: 0.2.0
dependencies: —
runtime: serviceDomainModule
persistence: tn_service_*
transport: Symfony /api/v1/service
events: service.*
```

## Канонічний життєвий цикл

```text
Create Request
      ↓
ServiceCase + Request
      ↓
Create Ticket
      ↓
Assign + Set SLA
      ↓
Escalate (за потреби)
      ↓
Resolve
      ↓
Close
```

Ticket має стани `open → assigned/escalated → resolved → closed`. Перепризначення escalated ticket не скидає escalation state. Закриття дозволене лише після Resolution.

## Власність

Service володіє:

- `ServiceCase`;
- `Request`;
- `Ticket`;
- історією `Assignment`;
- SLA snapshot та response/resolution deadlines;
- історією ескалацій;
- `Resolution`;
- lifecycle events та audit trail.

Email, Telegram, телефонія та зовнішні helpdesk не належать Service. Вони підключаються через Platform Integration / Notification boundaries.

## Постійний стан

```text
tn_service_cases
tn_service_requests
tn_service_tickets
tn_service_assignments
tn_service_slas
tn_service_escalations
tn_service_resolutions
tn_service_operation_receipts
```

Усі записи tenant-scoped через `organization_id`.

## Інваріанти

- кожен write-сценарій має idempotency receipt;
- той самий key з іншим payload є conflict;
- Ticket mutation серіалізується `SELECT ... FOR UPDATE`;
- Assignment, SLA, Escalation та Resolution зберігаються як історичні записи;
- `resolved` і `closed` блокують нові operational mutations;
- `Close` дозволений лише зі стану `resolved`;
- Request закривається, коли всі його Tickets закриті;
- ServiceCase закривається, коли всі його Requests закриті;
- Event + Audit пишуться в тій самій transaction boundary.

## API

Symfony surface:

```text
POST /api/v1/service/requests
GET  /api/v1/service/requests/{id}
POST /api/v1/service/requests/{id}/tickets
GET  /api/v1/service/tickets/{id}
POST /api/v1/service/tickets/{id}/assignments
PUT  /api/v1/service/tickets/{id}/sla
POST /api/v1/service/tickets/{id}/escalations
POST /api/v1/service/tickets/{id}/resolution
POST /api/v1/service/tickets/{id}/close
```

- [Процес Service Request → Close](../../02-workflows/service-request-to-close.md)
- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Покриття доменів процесами](../../12-reference/domain-process-coverage.md)
