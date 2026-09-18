---
title: Persistence, черги та планувальник
description: Канонічна межа MySQL/Doctrine, Redis Messenger, Symfony Scheduler і runtime workers під час Symfony migration.
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Persistence, черги та планувальник

## Database без одночасної зміни СУБД

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

## Async / Queue

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

## Scheduler

`CosScheduleProvider` створює schedule `cos`. Scheduler process лише визначає due messages; важка робота redispatch-иться в `async`, де її виконує звичайний worker.

Цей механізм є канонічним для scheduled agents, reports, synchronization, follow-ups, diagnostics і cleanup. Domain-specific recurring messages додаються лише разом із реальним Application command/use case, а не як порожні cron-заглушки.

## Docker runtime

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
