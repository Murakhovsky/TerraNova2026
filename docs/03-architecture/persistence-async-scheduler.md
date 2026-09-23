---
title: Зберігання даних, черги та планувальник
description: Канонічна межа MySQL/Doctrine, Redis Messenger, Symfony Scheduler і runtime workers під час Symfony migration.
status: active
updated: 2026-09-24
kind: architecture
contract: architecture-v1
---

# Зберігання даних, черги та планувальник

## База даних без одночасної зміни СУБД

На цьому етапі COS **не переходить на PostgreSQL**. Канонічна Symfony persistence база лишається MySQL:

```text
new Symfony-native storage
        ↓
Doctrine ORM / DBAL
        ↓
Doctrine Migrations
        ↓
MySQL cos_symfony
```

Legacy schema мігрує поступово:

```text
Legacy MySQL tables
        ↓
LegacyRepositoryAdapter
        ↓
Application / Domain contract
```

Legacy tables не оголошуються Doctrine entities заднім числом. Поточний приклад цього правила — `LegacyIdentityRepositoryAdapter`: він читає старі `tn_users` і `cos_organization_memberships` через окреме read-only PDO connection.

Нові persistence records розміщуються в `symfony/src/Persistence/Doctrine/Entity`, а нові schema changes — у `symfony/migrations`. Старі `app/migrations/*.sql` не конвертуються масово.

## Асинхронні задачі та черги

Symfony Messenger має Redis transport `async` і окремий `failed` stream. `CommandBusInterface` підтримує два режими: synchronous command із рівно одним handler result та asynchronous command, який отримує `SentStamp` і повертає caller без очікування handler.

Довгі AI, external API, sync та інші network-bound operations мають відправлятися в `async`, а не виконуватися всередині HTTP request.

```text
HTTP / CLI
   ↓
CommandBus
   ↓
Redis stream: cos_async
   ↓
worker
   ↓
Application handler
   ↓
AgentRuntime / Integration port
```

Поточний harmless `SchedulerHeartbeatCommand` є runtime probe цього маршруту. Він не є бізнес-функцією.

## Планувальник

`CosScheduleProvider` створює schedule `cos`. Scheduler process лише визначає due messages; важка робота redispatch-иться в `async`, де її виконує звичайний worker.

Цей механізм є канонічним для scheduled agents, reports, synchronization, follow-ups, diagnostics і cleanup. Domain-specific recurring messages додаються лише разом із реальним Application command/use case, а не як порожні cron-заглушки.

Growth V0.30 є першим provider-monitoring прикладом цього правила: `CosScheduleProvider` створює лише recurring `RunGrowthSignalPollingCommand`, redispatch-нутий в `async`. Worker через Growth-owned target read port знаходить organizations з enabled RSS/JSON sources і викликає canonical collector boundary. Scheduler не виконує provider HTTP calls сам і не має доступу до credential material. V0.31 додає read-only tenant-scoped status projection для `/growth/collectors`: вона показує operational readiness і cadence, але не змінює scheduler configuration та не розкриває cross-tenant target metadata.

## Середовище виконання Docker

Поточний мінімальний Symfony stack:

```text
nginx
php-fpm
mysql
redis
worker
scheduler
```

RabbitMQ, OpenSearch і Vector DB не додаються, доки немає виміряної потреби.
