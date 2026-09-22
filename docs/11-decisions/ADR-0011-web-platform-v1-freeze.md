---
title: ADR-0011 — Web Platform v1 зафіксовано після Sales cutover
description: Рішення заморозити фундаментальні контракти Web Experience Platform v1 після завершення Wave 12.26 і вимагати ADR для їх подальшої зміни.
status: accepted
updated: 2026-09-22
kind: decision
---

# ADR-0011 — Web Platform v1 зафіксовано після Sales cutover

## Контекст

Wave 12 завершив не лише набір UI-компонентів, а повну горизонтальну Web Experience Platform:

- Symfony SSR і Twig Components;
- module-owned Web extensions;
- `EntityRef`;
- unified `UIAction`;
- Workspace і Shell contracts;
- Forms і Data Platform;
- Realtime, Async Operations і AI UI;
- Mobile/PWA;
- Native-ready contracts;
- Security, Feature Flags, Audit/History;
- UI Catalog;
- testing, performance та observability;
- референсний Sales vertical;
- production Sales cutover.

Після Wave 12.26 платформа перевірена не лише dev surfaces, а production bounded context.

Наступний ризик — нескінченно продовжувати змінювати foundation під кожну нову vertical. Це знову перетворило б platform work на рухому мішень.

## Рішення

**Web Experience Platform v1 заморожується як канонічний фундамент COS.**

Фундаментальні public contracts перелічені в `tests/architecture/contracts/web_platform_v1.php`.

Зміна будь-якого contract із цього списку після freeze допускається лише коли той самий change set містить новий або оновлений ADR у `docs/11-decisions/`.

CI перевіряє це правило відносно base commit pull request або попереднього commit у `main`.

## Що саме заморожено

Freeze охоплює категорії contract API:

1. Module Web extension points.
2. `EntityRef`.
3. `UIAction` intent / placement / confirmation / danger semantics.
4. Web extension provider interfaces.
5. Workspace definition, slots і public view model.
6. Shell public view models.
7. DataGrid URL/state/page/column/filter contracts.
8. Forms input/draft/error contracts.
9. Realtime topic identity.
10. Device capability та compatibility contracts.
11. Native surface і bridge interfaces.
12. Deep-link public model.

Це **не** означає, що файли ніколи не можна змінювати. Це означає, що така зміна є архітектурним рішенням, а не локальним refactor.

## Що не заморожено

Без ADR можуть еволюціонувати, якщо не ламають frozen contracts:

- controllers;
- Application query/command handlers;
- Domain-owned providers;
- Twig templates;
- CSS;
- Stimulus controllers;
- product-specific layouts;
- нові canonical components;
- adapters;
- performance tuning;
- accessibility fixes;
- implementation details resolver/factory/service classes.

Новий optional capability також не потребує ADR, якщо він не змінює frozen contract semantics і не створює паралельну foundation.

## Правило сумісності

Для frozen contract перевага надається additive evolution:

- новий optional method через новий interface/versioned contract;
- нове enum value лише після impact review;
- backward-compatible DTO field із default;
- новий provider capability окремим extension point.

Breaking change потребує ADR із:

1. проблемою, яку неможливо вирішити additive способом;
2. impact на Web/Mobile/PWA/Native/Agents;
3. migration strategy;
4. compatibility window;
5. оновленням architecture gates;
6. оновленням reference vertical.

## Взаємодія з ADR-0009 і ADR-0010

- ADR-0009 визначає Web Experience Platform як канонічний UI runtime.
- ADR-0010 заморожує Web/UI foundation і забороняє паралельний frontend stack без ADR.
- ADR-0011 фіксує **versioned public contract surface Web Platform v1** після production Sales cutover.

ADR-0011 не supersede попередні рішення, а завершує їх.

## Контроль змін у CI

`tests/architecture/web_platform_v1_freeze.php` виконує два рівні перевірки:

1. статичний: protected contract inventory існує, ADR/index містять freeze policy;
2. change-control: якщо protected contract змінився відносно base SHA, diff також повинен містити ADR-файл.

Якщо CI не має base SHA, статичний contract все одно виконується, але pull request workflow завжди передає base SHA.

## Наслідки

Позитивні:

- нові Domains будуються на стабільній платформі;
- reference Sales vertical стає реальним compatibility anchor;
- foundation debt перестає маскуватися під feature work;
- mobile/native/agent consumers отримують прогнозовані contracts;
- архітектурні зміни стають видимими і поясненими.

Компроміс:

- breaking refactor потребуватиме ADR;
- деякі локально «прості» зміни доведеться робити additive;
- platform team бере на себе відповідальність за versioning.

Це навмисно. Після production cutover фундамент має бути нудним. Нудний фундамент — одна з небагатьох форм прогресу, які програмісти стабільно недооцінюють.
