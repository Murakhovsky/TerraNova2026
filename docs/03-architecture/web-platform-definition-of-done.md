---
title: Definition of Done Web Experience Platform v1
description: Канонічна матриця завершеності Wave 12 для архітектури, foundation, runtime, platform capabilities, quality, operations та Sales reference vertical.
status: active
updated: 2026-09-22
kind: architecture
---

# Definition of Done Web Experience Platform v1

Wave 12 вважається завершеним не за кількістю створених сторінок, а коли кожна capability з ТЗ має executable evidence у `main`.

Джерело машинної правди:

`tests/architecture/contracts/web_platform_definition_of_done.php`

Агрегуючий gate:

`tests/architecture/web_platform_definition_of_done.php`

## Матриця завершеності

| Категорія | Що доведено |
| --- | --- |
| Архітектура | Web є Application adapter; немає internal REST ownership; Domain/Application не залежать від Twig/UI; extension contracts і Web Platform v1 Freeze діють |
| Foundation | Bootstrap mechanics, semantic tokens, themes, UX Toolkit, icons і canonical components |
| UI Runtime | Twig SSR, Turbo, Live Components, Stimulus та interaction runtime |
| Platform | Shell, Navigation, UIAction, Workspace/Extensions, DataGrid, Forms, Search, Commands та EntityLink contracts |
| Realtime | Mercure topics, connection state, Async Operations та Activity Center |
| AI | Agent UI, structured result rendering, governed actions та AI UI context |
| Workflow | Kernel transitions/guards, Symfony state manager, unified runtime actions та audit/history |
| Mobile | responsive composition, mobile navigation/actions, DataGrid/form mobile contracts та SurfaceContext |
| PWA | manifest, installability, offline fallback, service-worker/update foundation |
| Native-ready | DeviceCapabilities, NativeBridge, Device Registry, ClientVersion та DeepLink |
| Security | CSRF, authorization, tenant isolation, uploads/downloads, rate limits, idempotency, locking/concurrency |
| Quality | architecture, component, functional, Panther-ready, browser visual/mobile/accessibility coverage |
| Operations | correlation, HTTP metrics, frontend telemetry/error monitoring та performance budgets |
| Reference Vertical | Sales Dashboard, Lead List і Lead Workspace з mobile/realtime/AI/workflow/history, production cutover та legacy deletion |

## Правило доказу

DoD gate не дублює всі нижчі тести. Він перевіряє:

1. усі категорії ТЗ присутні;
2. кожна категорія має executable evidence;
3. evidence-файли реально існують;
4. критичні докази підключені до CI;
5. Web Platform v1 Freeze прийнятий;
6. production Sales routes залишаються на canonical Symfony/Twig controller;
7. retired Sales UI ownership не повернувся.

Нижчі architecture/runtime/browser gates продовжують перевіряти власну детальну семантику.

## Статус після Wave 12

Після green DoD:

```text
Business Domain
  ↓
Queries / Commands / Workflows / Permissions
  ↓
UI Providers
  ↓
COS Experience Platform
  ↓
Desktop / Mobile Web / PWA / Native-ready / AI / Realtime
```

Новий Domain не створює власний frontend foundation.

## Подальші зміни

Fundamental Web Platform v1 contracts підпадають під ADR-0011.

Product UI, Twig composition, CSS, adapters, accessibility, performance та additive capabilities можуть еволюціонувати без нового ADR, якщо не ламають frozen contract surface.

Definition of Done не означає «UI більше не змінюється». Він означає, що платформа перестала бути незавершеним фундаментом.
