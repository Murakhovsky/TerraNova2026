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

## Безпечний попередній аудит AWS dev (2026-10-16)

Коли у гілці Federation змінюється файл workflow, запуск через push виконує
**окремий попередній аудит інфраструктури** із захищеним середовищем GitHub
`AWS TN2026`. Для нього необхідні наявні SSH-секрети та `DEV_PATH`,
але не потрібен ідентифікатор tenant. Аудит лише читає доступну оперативну
пам'ять, місце на диску, стан контейнерів Docker і маршрути Symfony.
Контрольна сума розгорнутого файлу маршрутів зіставляється зі станом `main`
та Federation. Аудит не розгортає код, не створює tenant, не змінює БД,
не запускає міграції та не перезапускає контейнери. Успішний job означає
виключно успішне збирання відомостей, **не приймання staging чи Golden 50**.

Повний аудит технічного виконання конкретного tenant запускається окремо
лише вручну через `workflow_dispatch`. Для нього потрібні дійсний tenant,
точний SHA розгорнутого коду та дозвіл оператора і захищеного середовища.

**Виявлена невідповідність:** поточне розгортання `main` використовує
`rsync --exclude='.git/'`, але попередня реалізація повного live-аудиту
вимагає `DEV_PATH/.git` і помилково прирівнює SHA розгорнутого коду
до SHA workflow з гілки Federation. Тому попередній аудит не закриває
відсутню атестацію фактичної версії. Перед повним аудитом і staging
потрібно додати незмінний маніфест релізу з перевіркою поточних PHP
контейнерів, а також ізолювати tenant, БД і Docker Compose project.
Не можна підміняти SHA чи вважати доступну сторінку `/workspace/goals`
доказом успішного Golden 50.
