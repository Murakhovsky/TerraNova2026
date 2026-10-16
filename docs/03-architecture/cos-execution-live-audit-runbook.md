---
title: COS Execution Consolidation — аудит реального tenant
description: Read-only аудит на фактичному розгортанні з підтвердженням SHA, execution workers і нативних receipt.
status: active
updated: 2026-10-16
kind: architecture
---

# Аудит реального tenant для Execution Consolidation

CI MySQL перевіряє лише ізольовані fixture cohorts, не production. Для підтвердження реального tenant існує окремий GitHub Actions workflow `COS Execution Live Tenant Audit`. Він використовує protected environment `AWS TN2026`, SSH credentials `DEV_HOST`, `DEV_USER`, `DEV_PORT`, `DEV_SSH_KEY`, каталог `DEV_PATH` та обов'язковий ідентифікатор існуючого tenant.

Автоматичний push-trigger допускається тільки за явно налаштованої protected environment variable `COS_EXECUTION_AUDIT_TENANT`. Для ручного `workflow_dispatch` необхідні `tenant_id` і `expected_sha`. Розгортання повинно вже містити точний SHA workflow: інакше аудит відмовляє, **не здійснюючи deploy**.

Workflow перевіряє Git SHA на VPS, SHA файла CLI всередині HTTP/queue/Kernel/Engineering контейнерів та ідентичність активного Docker image, а тоді тільки читає `cos:workflow:authority-inventory TENANT`. Звіт не виводить детальні native IDs у Actions logs, друкує статуси контурів і криптографічний digest сирого read-only JSON та видаляє локальний тимчасовий файл.

**Fail-closed:** відсутність credentials, реального tenant, актуального коду, активного execution consumer, повного tenant cohort, незалежних native receipts чи будь-який drift блокують workflow. Технічний аудит не дає права автоматично переводити rollout у canonical, здійснювати retry, replay чи merge. Golden 50 і незалежний release sign-off мають окремі gates.

## Неприпустимі стани технічної консолідації

Навіть за наявності трьох read-only звітів комбінована команда повертає `incomplete`, якщо лишилися непромотовані історичні Federation Runs, Engineering Features без rollout-атестації, поточні/невизначені Engineering claims, або Kernel не має достовірних незалежних receipts. Окремо виявляються Engineering links без native AgentRun чи canonical Run та canonical technical Runs без links; це `drift`. Обсяг Engineering cohort рахується від **усіх** tenant Features, не від pilot allowlist. Protected live workflow звіряє вихід із розгорнутим Git SHA і повним manifest production PHP code/DI на кожному активному PHP контейнері; наявність GitHub workflow без реально успішного job не є доказом перевірки.
