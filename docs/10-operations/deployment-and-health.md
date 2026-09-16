---
title: Розгортання та перевірка стану
description: Операційний контракт розгортання, готовності та перевірки працездатності середовищ COS.
status: active
updated: 2026-09-16
kind: operations
---

# Розгортання та перевірка стану

Розгортання вважається завершеним не тоді, коли файли опинилися на сервері, а коли **schema, runtime, workers, routes і критичні health checks узгоджені з одним commit**.

## Послідовність розгортання

```text
Зібрати та перевірити commit
        ↓
Застосувати сумісні migrations
        ↓
Розгорнути application/runtime
        ↓
Запустити або перезавантажити services і workers
        ↓
Health / readiness checks
        ↓
Критичні smoke checks
        ↓
Спостерігати errors / queues
```

Точні команди конкретного середовища можуть відрізнятися. Ця сторінка визначає операційний контракт, а environment-specific deployment files залишаються виконуваним джерелом істини.

## Рівні перевірки

### Стан процесу

Process/container живий. Це найслабший сигнал і сам по собі майже нічого не доводить.

### Готовність залежностей

Необхідні DB/runtime dependencies доступні, configuration валідна, migrations сумісні з поточним кодом.

### Стан застосунку

Канонічний health endpoint відповідає, а application bootstrap завершується без помилки.

### Бізнесова smoke-перевірка

Кілька критичних read/write flows працюють через нормальні application boundaries, а не лише через прямий SQL чи ручний обхід.

## Готовність worker-ів

Queue/outbox/async consumers перевіряються окремо на:

- bootability;
- lease/heartbeat semantics;
- backlog;
- retry/error state;
- dead-letter state, якщо він використовується.

Зелений Web endpoint не доводить, що worker не помер учора ввечері. Комп’ютери мають неприємну звичку ламатися саме там, де люди перестали дивитися.

## Правило rollback

Rollback application code не означає автоматичний rollback schema.

Migration strategy має враховувати:

- backward compatibility;
- порядок deploy/rollback;
- незворотні data transforms;
- сумісність старого коду з уже застосованою schema.

## Розгортання документації

Документація має власний build/deploy pipeline: [Збірка документації](./documentation-build.md). Narrative docs і generated reference повинні відповідати тому самому поточному commit.

## Пов’язані сторінки

- [Готовність модулів](./module-readiness.md)
- [Дані та міграції](./data-and-migrations.md)
- [Спостережуваність та інциденти](./observability-and-incidents.md)
