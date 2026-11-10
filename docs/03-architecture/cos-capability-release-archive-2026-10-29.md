---
title: Архів версій контрактів Capability — контрольоване формування релізів
description: Незмінні локальні snapshot-файли контрактів і схем для аудиту Goal Plan без автоматичного повторного виконання.
status: implementation-draft
updated: 2026-10-29
kind: architecture
---

# Незмінні історичні Capability Contracts

## Архітектурний принцип

Актуальне джерело контрактів і надалі залишається в `app/Domains/*/module.php`. Історичний архів містить лише зафіксовану копію **раніше випущеної версії** договору між Domain і виконавцем, разом із повним вмістом обох JSON-схем та SHA-256 відбитками. Архів потрібен для розслідування, відтворення планів і доказів сумісності. Він не створює нового власника функції або другого виконавця.

Стандартний шлях: `resources/contracts/history/{id}/{version}/{contract_digest}.json`. Файл фіксує `contract`, `input_schema`, `output_schema` та `snapshot`. Відбитки відтворюються з даних, а не довіряються назві файла чи збереженому JSON.

## Процедура створення

Після проходження CI та погодження зміни в Domain:

~~~bash
php bin/cos-capability-release-freeze.php
php bin/cos-capability-release-freeze.php --write
php bin/cos-capability-release-freeze.php --check
php tests/unit/cos_capability_immutable_archive.php
~~~

Перша команда лише показує, які контракти ще не заморожені. `--write` експліцитно створює файли через ексклюзивне відкриття, **ніколи не перезаписує** вже наявний артефакт. `--check` відхиляє відсутні поточні архіви або розбіжності. Створені JSON-файли необхідно додати окремим комітом та пропустити через review. Не вмикати цей gate до першого контрольованого baseline release.

Якщо поточний `id@version` змінив контракт або схему, генератор створить **інший digest**. Це ознака drift/версіонування, її необхідно розв'язувати через policy і збільшення версії, а не видавати новий digest під старим контрактом. Старі файли зберігаються.

## Безпека та межі доступу

- `CapabilityVersionArchive::read` перевіряє locator, локальну довірену директорію, обмеження розміру, форму JSON, typed contract, обидві схеми й усі поля fingerprint. Symlink або довільний шлях поза архівом відхиляється.
- `GET /api/v1/capabilities/{id}/versions/{version}/snapshots/{digest}` читає тільки запитаний уже заархівований контракт, після tenant-manager admission та перевірки owner. Не є endpoint для виконання.
- Дії `GoalCapabilityPinGuard`, `CanonicalCapabilityCatalog::resolvePinned`, `Workflow Action` та всі Plan/Approval/Worker gates **не перемикаються** на старий handler. Зміна версії не може непомітно спричинити повторне виконання.
- Архів не підтверджує статусу worker, доступності модуля в tenant, native receipt чи досягнення Goal.

## Приймання і залишок

Цей інкремент реалізує механізм freeze/read/verify. Реальний історичний release corpus виникне лише після коміту підписаних snapshot-файлів поточних typed контрактів. До цього endpoint правильно повертає unavailable для відсутніх історичних версій.

Для виконання **старої** версії у майбутньому необхідна окрема розробка: registry сумісних старих handlers, перевірка Deployment SHA, Policy/Approval parity, ідемпотентності, native readback, replay safety та операторське погодження. До цього старі Plans залишаються fail-closed; наявність архіву не змінює правила.
