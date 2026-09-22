---
title: "Впровадження Visualization у production UI"
description: "Канонічне впровадження Visualization surfaces без зміни Architecture Graph, projection або browser interaction semantics."
status: active
updated: 2026-09-22
kind: architecture
---

# Впровадження Visualization у production UI

PHASE 13 починається після закриття WEB V0.17 і працює вже не як міграція старого Workspace, а як post-migration visual debt cleanup по живих routed surfaces.

## Хвиля 1

### Architecture Explorer

`visualization/architecture.phtml`

- legacy breadcrumb + listing hero замінено на canonical PageHeader;
- active graph summary переведено на canonical KPI geometry;
- page/backend diagnostic feedback використовує canonical State;
- Graph Health переведено на canonical Panel + ActionBar;
- legacy `tn-admin-panel`, `tn-section-heading`, `tn-form-status`, `tn-kicker` прибрані;
- мертвий hero CSS видалено;
- manager-only controller, routes та Vite entrypoint не змінені.

## Межа specialized graph UX

Architecture Explorer не є звичайним CRUD/workspace screen. Його ключова цінність у спеціалізованому інтерактивному graph UI.

Тому PHASE 13 Wave 1 свідомо зберігає:

- projection toolbar;
- search/domain/depth controls;
- Cytoscape stage;
- node-type filters;
- node details;
- fit/reset/focus/collapse behavior;
- server-side projection hydration;
- embedded non-executable JSON payload;
- health JSON route.

`data-architecture-*` markers є browser contract і не повинні змінюватися під час presentation cleanup.

## Критерії завершення

- Architecture Explorer використовує canonical PageHeader, State, KPI, Panel і ActionBar;
- legacy workspace shell primitives не повертаються;
- specialized graph CSS/JS лишається локальним;
- Cytoscape version pin та hydration fallback не змінені;
- manager authorization і Architecture Graph service contracts не змінені;
- PHASE 13 architecture gate запускається у CI.
