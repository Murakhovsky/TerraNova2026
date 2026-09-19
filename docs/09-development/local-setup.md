---
title: Локальний запуск COS
description: Канонічне локальне середовище виконання та документації для розробки COS.
status: active
updated: 2026-09-16
kind: how-to
---

# Локальний запуск COS

Поточна базова конфігурація середовища виконання: PHP 8.2+ і Phalcon 5.9+. Робочий образ для розгортання використовує PHP 8.3 / Phalcon 5.19. MySQL є надійним джерелом правди для стану; Redis не є обов’язковою залежністю середовища виконання.

## Шлях через Docker

1. Скопіюйте `.env.docker.example` у `.env.docker`.
2. Замініть шаблонні значення та секрети на локальні.
3. Запустіть compatibility/SSR stack:

```bash
docker compose --env-file .env.docker up -d --build
```

4. Після створення legacy network/session volume запустіть канонічний Symfony runtime:

```bash
bash deploy/symfony-dev.sh
```

Legacy `migrate` застосовує SQL-міграції. Symfony обслуговує канонічний `/api/v1/*`, а Phalcon тимчасово залишається для SSR/compatibility поверхонь. Symfony Messenger/Scheduler використовують Redis; схема БД залишається під контролем deployment migrations, тоді як Symfony app-user має лише DML-доступ до legacy schema.

Перевірка середовища виконання:

```text
GET /api/v1/health
```

## Шлях через локальний PHP

Після налаштування потрібних розширень PHP та MySQL:

```bash
php app/bootstrap_cli.php migration up
php -S 127.0.0.1:8080 -t public public/router.php
```

## Документація

Згенерувати довідники, перевірити їх і запустити документацію локально:

```bash
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:dev
```

Для збірки, еквівалентної робочому розгортанню:

```bash
npm run docs:build
```

Команда `docs:check` також запускає перевірку мови для бізнесового та інтеграторського корпусу.

## Перед зміною архітектури

Прочитайте [карту репозиторію](../00-start/repository-map.md), [карту системи](../03-architecture/system-map.md) та огляд відповідного домену. Це дешевше, ніж спочатку провести залежність у неправильний бік, а потім урочисто її рефакторити.

## Швидка перевірка після запуску

Після підняття локального середовища переконайтеся щонайменше в трьох речах:

- `/api/v1/health` відповідає без помилки;
- потрібні міграції застосовані;
- документація проходить `npm run docs:check` перед комітом змін у її канонічний корпус.
