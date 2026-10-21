---
title: Легкий Docker-деплой COS на VPS
description: Публікація SHA-pinned образів через GHCR та запуск основних контейнерів на малому VPS.
status: active
updated: 2026-10-21
kind: operations
---

# Легкий Docker-деплой COS

## Що змінилося

GitHub Actions Runtime перевіряє PHP/Nginx образи з BuildKit GHA кешем. Тільки після успішних Runtime, Federation MySQL та Capital Markets gates окремий **publish-runtime-images** job випускає два образи з незмінним Git commit SHA:

- `ghcr.io/murakhovsky/terranova2026-php:<40-hex-sha>`;
- `ghcr.io/murakhovsky/terranova2026-nginx:<40-hex-sha>`.

Публікація повторно використовує cache layers GitHub Actions. VPS не компілює PHP extensions, не встановлює Composer-пакети і не збирає Nginx. Для приватних GitHub Packages доступ налаштований через короткоживучий `GITHUB_TOKEN` із `packages: read`. Початкова публікація може вимагати дозволу пакету на доступ Actions від репозиторію.

## Що запускає малий VPS

Режим `COS_DEPLOY_MODE=registry COS_DEPLOY_TIER=core` запускає лише **MySQL, Redis, Mercure, PHP, nginx та один async worker**. Він зупиняє попередні стандартні Engineering, Kernel, Scheduler, Spatial, Integration та Telegram воркери. Це має функціональні обмеження: спеціалізовані черги, scheduled tasks, автоматизація, FOS streaming та engineering execution **не працюють**, доки воркери не ввімкнено свідомо.

Режим `COS_DEPLOY_TIER=full` зберігає повний звичайний runtime для достатньої RAM. `COS_DEPLOY_MODE=build` лишився для локальної збірки; на 2 GB VPS його не використовувати.

## Дані та безпека

- `deploy/dev.sh` в registry mode відхиляє образи з неповним чи різним Git SHA.
- AWS dev workflow **не розгортає** COS, якщо на хості немає готового `.env` або `.env.docker`. Не можна автоматично створювати нову БД замість втраченої без явного рішення про відновлення.
- `.dockerignore` виключає `tests/docs/archive/output/public/uploads` із *контексту збірки*. Це не видалення файлів репозиторію або Docker volumes.
- Старі статичні `public/uploads` треба відновити з backup/сховища, якщо потрібен перегляд об'єктів.
- Для перевірки диску: `sudo docker system df -v` та `sudo docker buildx du`. **Не використовувати** `docker system prune --volumes`.

## Приймання

1. Runtime + Federation MySQL + Capital Markets gates PASS.
2. GHCR публікація двох SHA-tagged образів PASS.
3. На VPS існують відновлені secrets, DB та потрібні uploads.
4. Core deploy без збірки: `COS_DEPLOY_MODE=registry COS_DEPLOY_TIER=core`, запущено базові контейнери.
5. Symfony health, HTTPS, міграції та функціональний smoke PASS.
6. Після виміру RAM і CPU вибірково активувати deferred workers.

Код GitHub і образи GHCR **не замінюють** backup MySQL та master encryption keys.
