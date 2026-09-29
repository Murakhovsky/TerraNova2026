---
title: Перенесення запису Sales
description: "Друга хвиля другої фази міграції: перенесення основних Sales write-сценаріїв у Symfony без дублювання бізнес-логіки та persistence."
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Перенесення запису Sales

## Мета

Wave 2 переносить основні Sales write scenarios у канонічний Symfony runtime. Symfony не відтворює стару Phalcon business logic: Application commands викликають наявні Sales services та use cases через tenant-scoped write boundary.

## Канонічний шлях

```text
HTTP /api/v1/sales/*
        ↓
SalesWriteController
        ↓
CommandBusInterface
        ↓
Sales Application Command Handler
        ↓
SalesWriteServiceFactoryInterface
        ↓
SalesWriteService
        ↓
existing Sales use cases / services
        ↓
MySQL Infrastructure adapters
        ↓
legacy MySQL
```

## Сценарії Wave 2

| Сценарій | Symfony API |
| --- | --- |
| Створити Lead | `POST /api/v1/sales/leads` |
| Оновити Lead | `PATCH /api/v1/sales/leads/{id}` |
| Lead → Opportunity | `POST /api/v1/sales/leads/{id}/opportunity` |
| Додати Activity | `POST /api/v1/sales/opportunities/{id}/activities` |
| Змінити Pipeline Stage | `POST /api/v1/sales/opportunities/{id}/stage` |
| Встановити Next Action | `PUT /api/v1/sales/opportunities/{id}/next-action` |

## Ізоляція та безпека

- Organization береться тільки з `TenantContext`.
- Actor береться тільки з authenticated tenant context.
- Session-authenticated mutations вимагають legacy-compatible CSRF token.
- Create Lead та Next Action вимагають `X-Idempotency-Key`.
- HTTP correlation id прокидається в canonical Sales events.
- Інший tenant не може змінити Lead або Opportunity навіть за відомим numeric id.

## Бізнес-логіка

Wave 2 не створює альтернативну Sales модель.

Використовуються:

- `SalesInboundService` для Lead update та Lead → Opportunity;
- `ClientCaseCommandService` для Activities;
- `ChangeDealStage` для Pipeline transitions;
- `ScheduleDealFollowup` для Next Action;
- `EventBus` + durable outbox для domain events.

Infrastructure factory збирає organization-scoped runtime з одного PDO connection, transaction manager та event bus.

## Ідемпотентність

Create Lead використовує `sales_operation_receipts`. Receipt і Lead створюються в одній DB transaction.

Next Action використовує існуючий `cos_external_references` idempotency contract.

Повтор того самого request key не створює другий Lead або Activity.

## Історія стадій

Wave 2 видаляє застарілий direct write у V0.6.7 schema `sales_deal_stage_history` із `MysqlClientCaseCommandRepository::createCase()`.

З V0.8.1 stage history є event-owned projection. Створення Client Case публікує `ClientCaseCreated`, а durable historical consumer canonicalizes його у `DealCreated` та будує history projection.

## Сумісність

Legacy `/api/sales/*` і поточний frontend залишаються робочими. Їх перенесення на `/api/v1/sales/*` виконується окремою хвилею після стабілізації read/write API.

## Критерії завершення

Wave 2 завершено, коли:

- усі шість write scenarios проходять через Symfony CommandBus;
- controller/application не мають SQL або прямого legacy access;
- CSRF, tenant isolation, idempotency і correlation перевірені;
- mutation зберігає domain event та durable outbox;
- повторний Create Lead / Next Action не дублює state;
- cross-tenant mutations не змінюють дані;
- architecture, application, Docker/MySQL integration та regression gates зелені.
