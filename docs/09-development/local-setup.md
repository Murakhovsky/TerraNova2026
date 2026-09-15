---
title: Local Setup
description: Canonical local runtime and documentation setup for COS development.
status: active
updated: 2026-09-15
kind: how-to
---

# Local Setup

Поточний runtime baseline: PHP 8.2+ і Phalcon 5.9+. Production image використовує PHP 8.3 / Phalcon 5.19. MySQL є durable source of truth; Redis не є обов'язковим runtime dependency.

## Docker path

1. Скопіюйте `.env.docker.example` у `.env.docker`.
2. Замініть placeholders/secrets.
3. Запустіть stack:

```bash
docker compose --env-file .env.docker up -d --build
```

`migrate` one-shot service застосовує SQL migrations до старту application/worker services.

Перевірка runtime:

```text
GET /api/health
```

## Native PHP path

Після налаштування project PHP extensions і MySQL:

```bash
php app/bootstrap_cli.php migration up
php -S 127.0.0.1:8080 -t public public/router.php
```

## Documentation

```bash
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:dev
```

Для production-equivalent documentation build:

```bash
npm run docs:build
```

## Before changing architecture

Прочитайте [Repository Map](../00-start/repository-map.md), [System Map](../03-architecture/system-map.md) та relevant Domain overview. Це дешевше, ніж спочатку написати dependency у неправильний бік, а потім урочисто її рефакторити.
