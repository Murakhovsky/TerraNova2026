---
title: Дані та міграції
description: Правила зберігання, tenant isolation, ownership міграцій і розвитку даних у COS.
status: active
updated: 2026-09-16
kind: operations
---

# Дані та міграції

## Джерело істини

MySQL залишається operational source of truth. COS використовує Events/Outbox для надійного поширення змін, але не є Event Sourcing system.

## Атомарний запис

Критичний write path має виглядати так:

```text
begin transaction
→ mutate business state
→ persist DomainEvent
→ persist Outbox row
→ commit
```

Неприпустимо спочатку закомітити state, а потім «якось відправити event». Це не eventual consistency, це eventual headache.

## Ізоляція tenant-ів

Business queries і mutations мають бути scoped by `organization_id` або еквівалентну tenant identity.

Tenant isolation перевіряється не лише UI/session layer, а application/persistence boundaries. Background worker також не повинен залежати від Web session для визначення організації.

## Власність міграцій

Installable module може декларувати свої migration files у manifest contributions.

Shared platform schema належить platform/kernel infrastructure; domain schema належить відповідному Domain/module.

## Правила міграцій

- migration є immutable після production застосування;
- sequence/name мають бути unique;
- наступна зміна робиться новою migration;
- destructive change потребує compatibility/deploy plan;
- schema version узгоджується з module versioning strategy;
- install/upgrade module lifecycle має знати, які migrations належать модулю;
- rollback strategy визначається окремо від бажання «повернути все назад одним кліком».

## Конкурентні записи

Для concurrency-sensitive aggregates, наприклад Diagnostic session або versioned data, використовуйте optimistic locking, якщо silent overwrite неприйнятний.

## Моделі читання

Read projections можуть денормалізувати дані для workspace/dashboard, але не стають authoritative write model.

```text
Canonical write model
        ↓
Events / projection update
        ↓
Read model
```

Відновлюваний read model не повинен тихо перетворюватися на друге джерело бізнесової істини.

## Спадкове зберігання

Phalcon ActiveRecord compatibility adapters у старих Sales/Property/Telegram paths залишаються quarantine zone. Новий Domain code не будується навколо них і не отримує прямий SQL-доступ до чужого Domain лише тому, що таблиця поруч.

## Повторне відтворення

Outbox replay має контрольовано відновлювати selected delivery/consumer state.

Replay не означає повторне безумовне виконання non-idempotent external side effects. Для consequential operation потрібна стабільна operation identity та idempotency protection.

## Операційна перевірка

Для persistence changes, де доречно, потрібні:

- migration test;
- real MySQL integration test;
- tenant isolation test;
- idempotency/retry test;
- event/outbox atomicity test;
- rollback/compatibility assessment;
- readiness check для модуля після застосування schema changes.

## Пов’язані сторінки

- [Розгортання та перевірка стану](./deployment-and-health.md)
- [Готовність модулів](./module-readiness.md)
- [Резервне копіювання та відновлення](./backup-and-recovery.md)
