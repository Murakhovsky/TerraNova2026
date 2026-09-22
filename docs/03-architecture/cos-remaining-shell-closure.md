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

## Хвиля 2

### Public Page

`page/show.phtml`

- legacy breadcrumbs/hero замінено на canonical PageHeader;
- generic CTA actions використовують canonical ActionBar;
- contact result використовує canonical State;
- contact form, honeypot, request intent, role, deal type та public `/contacts` POST contract не змінені.

### Blog

`blog/index.phtml`

- legacy breadcrumbs + page hero замінено на canonical PageHeader;
- empty state переведено на canonical State;
- blog grid, article cards та pagination лишаються specialized public content patterns;
- `blog?page={n}` pagination contract не змінений.

`blog/show.phtml`

- article identity/header переведено на canonical PageHeader;
- practical CTA використовує canonical ActionBar;
- related-content shell прибирає legacy section-heading/kicker;
- article body, cover, tags та related content лишаються editorial patterns;
- JSON-LD Article schema збережена без змін.

### SEO landing

`blog/landing.phtml`

- simple landing hero замінено на canonical PageHeader;
- featured image лишається окремим editorial media block;
- CTA використовує canonical ActionBar;
- body HTML та JSON-LD WebPage schema не змінені.

Public content controller/routes лишаються canonical Symfony contracts:

- `/blog` → `PublicContentPageController::blog`;
- `/blog/{slug}` → `article`;
- `/guide/{slug}` → `guide`.

## Принцип specialized surfaces

Canonical shell не означає, що specialized application повинна перетворитися на набір стандартних cards.

Architecture graph stage, projection toolbar, filters, node details та Cytoscape interaction model залишаються domain-specific. Канонізується shell і shared primitives навколо них.

## Наступні хвилі

- Wave 2: public content shells — Page / Blog / SEO landing — виконано;
- Wave 3: auth/cabinet entry surfaces;
- Wave 4: фінальний legacy-shell audit і classification specialized marketing/runtime surfaces.

## Критерії завершення

- Architecture Explorer використовує canonical PageHeader, KPI, State та panel shell;
- live graph counters не втратили DOM hooks;
- graph/projection/health routes та manager authorization збережені;
- legacy internal shell primitives не повертаються через PHASE 14 gate;
- specialized graph interaction model залишається недоторканим;
- Public Page, Blog та SEO landing використовують canonical shell primitives без втрати form/pagination/schema contracts;
- Article/editorial content і public schema лишаються specialized content patterns.
