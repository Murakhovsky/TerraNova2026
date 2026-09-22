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

### Публічні контентні surfaces

Wave 2 закриває outer-shell debt для Page / Blog / SEO landing без переписування domain-specific content.

#### Page

`page/show.phtml`

- legacy breadcrumbs та `tn-page-hero` замінено на canonical PageHeader;
- primary/secondary CTA проходять через canonical ActionBar;
- content sections і contact form зберігають існуючу структуру та mutation contract.

#### Blog Index

`blog/index.phtml`

- legacy breadcrumbs + page hero замінено на canonical PageHeader;
- published material count використовує canonical meta contract;
- empty state переведено на canonical State;
- blog cards і pagination залишаються specialized content pattern.

#### Blog Article

`blog/show.phtml`

- redundant breadcrumbs видалено;
- rich article header, cover, body, tags та Article schema залишаються specialized;
- CTA shell переведено на canonical Panel + ActionBar;
- related-content heading переведено на canonical panel anatomy.

#### Property SEO Landing

`property/seo.phtml`

- PageHeader/State лишаються canonical contract із PHASE 10;
- redundant breadcrumbs видалено;
- informational SEO panel переведено на canonical panel shell;
- schema.org BreadcrumbList/ItemList, property cards, pagination та favourite hooks не змінені.

Wave 2 не намагається перетворити article body, blog cards або property cards на generic primitives.

## Хвиля 3

### Auth і Cabinet entry surfaces

Wave 3 закриває outer-shell debt для входу, реєстрації та живого cabinet entry surface без зміни identity/session semantics.

#### Login

`auth/login.phtml`

- спеціалізований `tn-auth-layout` лишається;
- copy/header переведено на canonical PageHeader;
- submit control використовує canonical primary button;
- auth feedback використовує canonical alert geometry;
- POST route, email/password fields та autocomplete contract не змінені.

#### Register

`auth/register.phtml`

- specialized auth layout лишається;
- copy/header переведено на canonical PageHeader;
- submit control використовує canonical primary button;
- feedback використовує canonical alert geometry;
- role choices, password constraints і registration POST contract не змінені.

#### Cabinet

Production route `/cabinet` рендерить `cabinet/canonical.phtml`, а не історичний `cabinet/index.phtml`.

Live cabinet surface тепер використовує:

- canonical PageHeader;
- canonical Panel shell;
- canonical ActionBar;
- existing portal profile content.

`CabinetPageController` і native Symfony/COS identity/session lifecycle не змінюються.

Legacy `cabinet/index.phtml` не є routed production surface і класифікується у фінальному Wave 4 audit, а не канонізується штучно.

## Принцип specialized surfaces

Canonical shell не означає, що specialized application повинна перетворитися на набір стандартних cards.

Architecture graph stage, projection toolbar, filters, node details та Cytoscape interaction model залишаються domain-specific. Канонізується shell і shared primitives навколо них.

## Наступні хвилі

- Wave 2: public content shells — Page / Blog / SEO landing — виконано;
- Wave 3: auth/cabinet entry surfaces — виконано;
- Wave 4: фінальний legacy-shell audit і classification specialized marketing/runtime surfaces.

## Критерії завершення

- Architecture Explorer використовує canonical PageHeader, KPI, State та panel shell;
- live graph counters не втратили DOM hooks;
- graph/projection/health routes та manager authorization збережені;
- legacy internal shell primitives не повертаються через PHASE 14 gate;
- specialized graph interaction model залишається недоторканим;
- Page / Blog / SEO landing не використовують legacy breadcrumbs/page-hero/empty-state/section-heading shells там, де існує canonical primitive;
- article body, schema.org та property/blog content cards лишаються domain/content-specific;
- login/register зберігають auth field/POST semantics, але використовують canonical PageHeader/button/alert anatomy;
- live `/cabinet` використовує canonical PageHeader/Panel/ActionBar і не повертає legacy portal hero/section shell.
