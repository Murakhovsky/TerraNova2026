---
title: "Federation: ізольоване тестове середовище"
description: "Правила діагностики Docker, безпечного попереднього аудиту та підготовки Golden 50 без доступу до робочої бази."
status: draft
kind: operations
updated: 2026-10-16
---

# Ізоляція Federation та підготовка Golden 50

## Фактичний стан середовища

Під час незалежного read-only аудиту VPS 16.10.2026 виявлено
контейнери з префіксами `cos-*` та `cos-symfony-*`,
633 МіБ доступної RAM, близько 15 ГіБ вільного диска,
а `cos-worker-1` та `cos-kernel-worker-1` перезапускалися.
Це **не доводить** спільну БД або одночасний запис, але забороняє
вважати середовище підготовленим без додаткової перевірки.

## Діагностика без змін

`python3 bin/ops/cos_docker_fleet_audit.py --inspect-logs`

Команда читає Docker metadata, Compose labels, активні workers,
статус restart та перетини storage/network. Для проблемних workers
аналізує обмежену кількість рядків logs та друкує **тільки категорію**
помилки. Паролі, DSN та повні логи не публікуються. Статуси:
`PASS`, `REVIEW`, `BLOCKED`. Дві Compose-групи з активними
workers дають щонайменше `REVIEW`; crash loop дає `BLOCKED`.
Навіть `PASS` не підтверджує business-level single-writer.

Команда **не зупиняє** контейнери і не змінює БД. Визначати
причину падіння kernel-worker без exit code та діагностики logs
не можна. Для Messenger `--failure-limit=1` здатен створювати
цикл restart після помилок job, але це не доведений root cause.

## Staging без втручання в main

Виділена конфігурація:
`deploy/staging/federation-compose.yml`.
Compose project: `cos-federation-staging`. Окремі MySQL, Redis,
мережа, volumes, application image; зовнішні надсилання та
workers не запускаються. HTTP лише на `127.0.0.1:18081`,
домен `company-os.shop` не використовується.

**За замовчуванням staging НЕ запускається.** Для перевірки
доступності ресурсів і сумісності слід виконати:

`bash deploy/staging/guard.sh --inspect`

Guard вимагає щонайменше 2048 МіБ доступної пам'яті,
12288 МіБ вільного диска, відсутності restart loops і
розбіжностей Compose authority. На поточному VPS вимога RAM
не виконується, тому запуск має бути `BLOCKED`, доки ресурси
або архітектура стенда не змінені.

Окремий операторський запуск `--start` потребує явного
`COS_FED_STAGING_OPERATOR_APPROVAL=APPROVE_ISOLATED_FEDERATION_STAGING`,
пінованого `COS_FED_STAGING_SHA` та **окремих**
`COS_FED_STAGING_DB_PASSWORD`, `COS_FED_STAGING_ROOT_PASSWORD`,
`COS_FED_STAGING_APP_SECRET`, `COS_FED_STAGING_SPATIAL_SECRET`,
`COS_FED_STAGING_MERCURE_SECRET`.
Робочі секрети, production-dump і зовнішні токени не допускаються.
Ця дія створює **лише окремі staging контейнери**, не мігрує
схему, не створює tenant, не погоджує Actions і не виконує
Golden 50. Рішення щодо подальших міграцій та acceptance
потребує окремої перевірки й дозволу.

## Приймання

Порядок: стабілізація workers → незалежний Docker audit →
перевірка ресурсів та ізоляції → staging rollout → staging
MySQL/tenant → Golden 50 → незалежний evidence bundle →
операторський sign-off → merge. Жоден пункт не можна
замінити зеленим статусом статичних unit/architecture тестів.
