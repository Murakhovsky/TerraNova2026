---
title: Data and Migrations
description: Persistence, tenant isolation, migration ownership та data evolution rules COS.
status: active
updated: 2026-09-11
kind: operations
---

# Data and Migrations

## Source of truth

MySQL залишається operational source of truth. COS використовує Events/Outbox для reliable propagation, але не є Event Sourcing system.

## Atomic write guarantee

Критичний write path:

```text
begin transaction
→ mutate business state
→ persist DomainEvent
→ persist Outbox row
→ commit
```

Неприпустимо спочатку commit state, а потім «якось відправити event».

## Tenant isolation

Business queries і mutations мають бути scoped by `organization_id` або еквівалентний tenant identity.

Tenant isolation перевіряється не тільки UI filter, а persistence/application boundary.

## Migration ownership

Installable module може декларувати свої migration files у manifest contributions.

Shared platform schema належить platform/kernel infrastructure; domain schema — відповідному Domain/module.

## Migration rules

- migration immutable після production застосування;
- sequence/name unique;
- forward change через нову migration;
- destructive change потребує compatibility/deploy plan;
- schema version узгоджується з module versioning strategy;
- install/upgrade module lifecycle повинен знати, які migrations належать модулю.

## Optimistic locking

Для concurrency-sensitive aggregates (наприклад Diagnostic session/versioned data) використовується optimistic locking, якщо silent overwrite неприйнятний.

## Read models

Read projections можуть денормалізувати дані для workspace/dashboard, але не стають authoritative write model.

## Legacy persistence

Phalcon ActiveRecord compatibility adapters у Sales/Property Telegram paths залишаються quarantine zone. Новий domain code не повинен знову будуватися навколо них.

## Replay

Outbox replay має відновлювати selected delivery/consumer state контрольовано. Replay не означає повторне безумовне виконання non-idempotent external side effects.

## Operational checks

Для persistence changes потрібні, де доречно:

- migration test;
- real MySQL integration test;
- tenant isolation test;
- idempotency/retry test;
- event/outbox atomicity test;
- rollback/compatibility assessment.