---
title: Стратегія тестування Web Experience Platform
description: Канонічна матриця Wave 12.23 для модульних, функціональних, компонентних, браузерних, візуальних, мобільних, accessibility та архітектурних перевірок COS.
status: active
updated: 2026-09-21
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
| Accessibility | labels, accessible names, alt, unique ids, keyboard focus, document language/title |
| Architecture | `tests/architecture` + Wave 12.23 meta-gate |

## Браузерна стратегія

Поточний executable browser layer використовує `playwright-core`, який уже є locked dependency COS. Він перевіряє public Symfony surfaces у desktop і mobile режимах, збирає screenshots і падає на browser runtime errors.

Panther suite зберігається у `symfony/tests/Panther`. Він є Symfony-native browser contract і готовий до увімкнення після додавання `symfony/panther` як locked dev dependency. До цього моменту CI не прикидається, що Panther встановлено: реальний browser gate виконує Playwright.

## Базова доступність

CI baseline ловить високосигнальні регресії: відсутній `lang` або `title`, duplicate ids, form controls без label, images без `alt`, interactive controls без accessible name, неможливість увійти в keyboard focus, horizontal overflow у mobile viewport та browser console/page errors.

Це не замінює повний аудит WCAG. Пізніше можна додати axe/pa11y без зміни architecture contract.

## Візуальна перевірка

Pixel-perfect snapshots поки не є глобальним контрактом, бо visual migration ще триває. CI генерує desktop/mobile screenshots, перевіряє non-blank pixel entropy, runtime errors та responsive overflow. Після стабілізації visual language окремі reference surfaces можуть отримати golden snapshots.

## Правило зміни

Код, який змінює Web Platform behavior, має додати або оновити evidence на тому рівні, де виникає ризик. Відсутність functional або browser coverage не можна компенсувати ще одним architecture grep-тестом.
