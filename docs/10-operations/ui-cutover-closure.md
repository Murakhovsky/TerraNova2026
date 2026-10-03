---
title: Production Cutover UI Closure
description: Технічне завдання на закриття фактичних UI gaps, знайдених під час route-by-route acceptance аудиту.
status: active
updated: 2026-10-03
kind: operations
---

# Закриття UI для Production Cutover

## 1. Мета

Перевести Web UI COS з режиму «route існує» у режим практичної browser-operability для ключових surface-ів.

Критерій простий:

> Backend capability не вважається доступним користувачу, поки intended operation неможливо виконати через browser UI або поки surface явно не визначений як machine/frame endpoint.

## 2. Базовий стан

Baseline: `main@72dcbb13ff091ed0836485ae90a872b33d4566df`.

Інвентар Web GET/HEAD routes через `App\Web\...`: **103**.

Аудит виділив п’ять фактичних closure-блоків:

1. Cabinet був identity-only surface і редіректив manager у Sales.
2. Engineering мав read-only Web UI, а create/run/human decision залишалися API/CLI-only.
3. Spatial functional acceptance падав на capture persistence.
4. Growth не мав deterministic enabled-tenant browser acceptance.
5. Workspace AI та public home були технічно коректними, але занадто слабкими як user-facing entry surfaces.

## 3. Кабінет

### Вимоги

- `/cabinet` не редіректить manager/admin у `/sales`.
- Будь-який authenticated user отримує власний Cabinet.
- Cabinet показує:
  - роль;
  - організацію;
  - permissions;
  - швидкі дії;
  - доступні workspaces.
- Manager отримує Sales, Client Cases, Property, Spatial.
- Admin отримує Executive Workspace, Engineering, Users, Control Center.
- Growth показується тільки якщо module активний для organization.
- Logout та повернення на public site залишаються доступними.

### Критерії приймання

- authenticated admin відкриває `/cabinet` з HTTP 200;
- присутній `[data-cos-portal="cabinet"]`;
- manager бачить link на `/sales/today`;
- admin бачить link на `/admin/engineering`.

## 4. Web-операції Engineering

### Вимоги

На `/admin/engineering`:

- форма створення feature;
- title;
- description;
- priority P0-P3;
- option «одразу запустити workflow»;
- CSRF.

На `/admin/engineering/{id}`:

- Start / Continue;
- Human Decision UI для open decision requests;
- selectable offered options;
- optional comment;
- status/error feedback;
- існуючі Tasks / AgentRuns / Findings / Final Report / Pull Request залишаються.

### Архітектурне правило

Web controller використовує існуючі Application services:

- `EngineeringOrchestrator`;
- `EngineeringContinueService`;
- `EngineeringHumanDecisionService`;
- `EngineeringStatusService`.

Business logic у Twig не дублюється.

### Критерії приймання

- feature створюється з browser form;
- після redirect відкривається dynamic feature workspace;
- feature присутній у collection після canonical reload;
- mutation без valid CSRF відхиляється.

## 5. Закриття Spatial capture

### Збереження даних

Створення version + capture є однією транзакцією.

Потрібно:

- lock scene row;
- serial allocation `version_number`;
- rollback version, якщо capture insert не завершився;
- validate supplied `version_id` against the same scene;
- повернути capture разом із version label/number.

### UI

`/spatial/edit/{id}` показує окремий Capture history.

Кожен capture містить:

- version label;
- version number;
- capture type;
- status;
- device;
- captured_at.

Error feedback не може рендеритися positive tone.

### Критерії приймання

- create scene;
- submit capture;
- response не містить `ERROR:`;
- виконати canonical reload dynamic edit page;
- capture з відповідним label існує у `[data-spatial-capture-history]`.

## 6. Browser acceptance для Growth

Growth залишається `enabled_by_default: false`.

Це правило **не змінюється**.

Для cutover CI:

- disposable tenant `default` явно отримує `growth=enabled`;
- після цього browser smoke перевіряє:
  - `/growth`;
  - `/growth/candidates`;
  - `/growth/accounts`;
  - `/growth/signals`;
  - `/growth/collectors`;
  - `/growth/learning`;
  - `/growth/experiments`;
  - `/growth/market`;
  - `/growth/settings`.

Очікування: HTTP 200 + canonical Growth workspace marker.

Жодного production-wide default enable.

## 7. Workspace AI

`/workspace/ai` не позиціонується як generic chat, доки generic conversational workflow не існує.

Surface має:

- context selector: workspace + entity;
- active UIContext;
- capabilities;
- available governed actions;
- recent AgentRuns;
- переходи до Sales Agents, Engineering Agents, Control Center.

## 8. Публічна головна сторінка

`/` перестає бути runtime signpost.

Мінімальна структура:

- COS value proposition;
- Real Estate entry;
- property submission;
- COS explainer;
- services/partners;
- authenticated Cabinet entry.

## 9. Контракт browser-регресії

`production_cutover_routes.mjs` повинен покривати нові entry surfaces.

`production_cutover_functional.mjs` повинен доводити:

- Cabinet role-aware navigation;
- Engineering browser create + persistence;
- Spatial capture persistence after reload.

Runtime workflow provision-ить Growth **лише для disposable CI tenant**.

## 10. Критерії завершення

Closure вважається завершеним, коли:

- CI / Frontend / Documentation зелені;
- Runtime зелений;
- route smoke зелений;
- production cutover functional зелений;
- Sales browser acceptance залишається зеленим;
- немає нового direct 500/503 на covered surfaces;
- немає regressions у auth / tenant isolation / CSRF;
- зміни зібрані в одну feature branch і мінімальну кількість commits.
