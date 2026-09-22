---
title: Стратегія тестування Web Experience Platform
description: Канонічна матриця Wave 12.23 для модульних, функціональних, компонентних, браузерних, візуальних, мобільних, accessibility та архітектурних перевірок COS.
status: active
updated: 2026-09-22
kind: architecture
---

# Стратегія тестування Web Experience Platform

Wave 12.23 закриває quality contract для Web Experience Platform. Мета не в кількості тестів, а в тому, щоб кожен тип ризику мав власний executable evidence.

## Матриця

| Рівень | Канонічне покриття |
| --- | --- |
| Unit | детерміновані model/service contracts у `tests/unit` |
| Functional | HTTP behavior через `tests/functional/web_platform_contract.sh` |
| Component | інвентар, template mapping і dependency boundaries у `tests/component` |
| Panther | source-controlled Panther suite для Symfony browser layer |
| Visual | Playwright screenshot sanity та non-blank pixel contract |
| Mobile | окремий 390×844 browser profile і horizontal-overflow gate |
| Accessibility | heuristic browser checks + pinned axe-core WCAG 2.2 A/AA audit |
| Architecture | `tests/architecture` + Wave 12.23 meta-gate |

## Браузерна стратегія

Поточний executable browser layer має два незалежні рівні доказів.

`playwright-core` є locked dependency COS і перевіряє public Symfony surfaces у desktop/mobile режимах, screenshots, runtime errors, responsive overflow та accessibility.

Symfony-native Panther suite у `symfony/tests/Panther` тепер також виконується реально в CI проти вже запущеного canonical runtime через `PANTHER_EXTERNAL_BASE_URI`. Щоб не забруднювати production dependency graph, CI створює isolated test-only Composer sandbox і встановлює exact `symfony/panther:2.4.0` разом із PHPUnit. Production Docker image, як і раніше, збирається `--no-dev`.

## Базова доступність

CI baseline ловить високосигнальні регресії: відсутній `lang` або `title`, duplicate ids, form controls без label, images без `alt`, interactive controls без accessible name, неможливість увійти в keyboard focus, horizontal overflow у mobile viewport та browser console/page errors.

PHASE 15 доповнює цей baseline pinned `@axe-core/playwright@4.13.0` audit для WCAG 2.x / 2.1 / 2.2 Level A + AA на reference public surfaces. Евристичний layer не видаляється: він ловить runtime, focus та overflow регресії, які не є повною заміною standards-based axe аналізу.

## Візуальна перевірка

Pixel-perfect snapshots поки не є глобальним контрактом, бо visual migration ще триває. CI генерує desktop/mobile screenshots, перевіряє non-blank pixel entropy, runtime errors та responsive overflow. Після стабілізації visual language окремі reference surfaces можуть отримати golden snapshots.

## Правило зміни

Код, який змінює Web Platform behavior, має додати або оновити evidence на тому рівні, де виникає ризик. Відсутність functional або browser coverage не можна компенсувати ще одним architecture grep-тестом.
