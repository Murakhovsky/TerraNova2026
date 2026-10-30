---
title: Контрольоване приймання Capability Library без автоматичного deploy
description: Порядок інтеграції #415, #416, #418, #419, #420, #421 та guard для GHCR/AWS dev.
status: implementation-draft
updated: 2026-10-30
kind: operations
---

# Приймання Capability Library без побічних релізних дій

## Причина

У чинному workflow `.github/workflows/runtime.yml` кожен push до `main`, що змінює runtime-файли, після перевірок автоматично запускає `publish-runtime-images` (GHCR) та `deploy-dev` (AWS dev). Тому простий merge навіть перевіреного Capability PR є зміною запущеного середовища.

## Вузьке правило інтеграції

Умови **обох** релізних jobs доповнено точною перевіркою merge-коміту: `!contains(github.event.head_commit.message, '[capability-no-deploy]')`. Якщо конкретний merge-коміт включає позначку `[capability-no-deploy]`, він запускає звичайні CI/Runtime checks для `main`, але **не** публікує образи та **не** деплоїть AWS dev.

Для решти main pushes без позначки поведінка не змінюється. Це контроль merge в межах цього релізу, не дозвіл обходити CI, не активація Workflow Action worker і не постійне вимкнення production pipeline.

## Перевірений порядок

1. Усі PR мають бути без конфліктів та з успішними checks на точних head SHA.
2. Першим прийняти PR #415: лише після успішного CI із цим guard і з merge title, що **точно** містить `[capability-no-deploy]`.
3. Після кожного merge перевірити, що `main` утримує обидва release guard, CI/Runtime на новому main SHA пройшли, а GHCR publish і AWS deploy обидва **SKIPPED**. Якщо будь-яка умова порушена, припинити інтеграцію, без подальших merges.
4. Далі у цьому самому режимі: #416 → #418 → #419 → #420 → #421, не переплутати порядок. Залежні гілки зберігають зміни guard від першого merge.
5. Нові PR із runtime fleet consistency та Domain adapters приймати лише після перевірки сумісності з уже злитим main.
6. Окремий операторський release процес і дозволи потрібні для deploy, tenant checks та можливого cutover worker.

## Заборони

Не використовувати `[skip ci]`, не змінювати Action worker із `FailClosed` без окремого production acceptance і rollback, не сприймати технічний output як Goal Outcome. Якщо будь-який із запусків GitHub не дає перевіреного статусу **SKIPPED** для publish/deploy, merge-ланцюжок зупиняється.

Тест `tests/architecture/cos_capability_safe_merge_release_guard.php` підтверджує наявність guard на обох jobs та незмінність CI triggers. Тест не може сам підтвердити відсутність AWS-сайд-ефектів, її перевіряють за фактичним post-merge GitHub Actions.
