---
title: "Закриття залишкових UI shells"
description: "PHASE 14 прибирає останні legacy shell primitives з production surfaces без знищення domain-specific interaction models."
status: active
updated: 2026-09-22
kind: architecture
---

# Закриття залишкових UI shells

PHASE 14 починається після завершення domain adoption та residual table closure. Його завдання — прибрати окремі legacy shell primitives, які ще живуть у production views, не перетворюючи specialized applications на однакові generic сторінки.

## Хвиля 1

### Architecture Explorer

`visualization/architecture.phtml`

Architecture Explorer лишається specialized graph application, але його outer shell переведено на canonical COS contracts:

- legacy breadcrumbs + `tn-listing-hero` замінено на canonical PageHeader;
- active projection, nodes, edges і graph health подані через canonical KPI cards;
- KPI card отримав generic `valueAttributes`, щоб live graph counters зберігали `data-architecture-*`;
- page/backend diagnostic states використовують canonical State;
- Graph Health shell використовує canonical panel classes;
- legacy `tn-admin-panel`, `tn-section-heading`, `tn-kicker` і локальні status blocks видалені.

Graph behavior не змінюється:

- server-side projections залишаються у `ArchitecturePageController`;
- `/cos/architecture/graph` і `/cos/architecture/health` не змінені;
- `data-architecture-mode/search/domain/depth/types/stage/details` збережені;
- JSON bootstrap `#cos-architecture-data` збережений;
- manager-only access лишається canonical authorization boundary.

## Принцип specialized surfaces

Canonical shell не означає, що specialized application повинна перетворитися на набір стандартних cards.

Architecture graph stage, projection toolbar, filters, node details та Cytoscape interaction model залишаються domain-specific. Канонізується shell і shared primitives навколо них.

## Наступні хвилі

- Wave 2: public content shells — Page / Blog / SEO landing;
- Wave 3: auth/cabinet entry surfaces;
- Wave 4: фінальний legacy-shell audit і classification specialized marketing/runtime surfaces.

## Критерії завершення

- Architecture Explorer використовує canonical PageHeader, KPI, State та panel shell;
- live graph counters не втратили DOM hooks;
- graph/projection/health routes та manager authorization збережені;
- legacy internal shell primitives не повертаються через PHASE 14 gate;
- specialized graph interaction model залишається недоторканим.
