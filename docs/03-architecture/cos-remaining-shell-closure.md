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

### Auth entry surfaces

`auth/login.phtml` і `auth/register.phtml` переведені на canonical entry-shell:

- canonical PageHeader замість окремого auth-copy hero;
- canonical State для authentication/registration result;
- canonical panel shell навколо form body;
- primary submit використовує canonical button contract;
- email/password/full_name/phone/role/password_repeat fields, autocomplete та POST routes не змінені.

Native Symfony auth lifecycle лишається у `AuthPageController`:

- authenticate/register викликають `AccountAuthenticationService`;
- успішний login/register виконує `session->migrate(true)`;
- session зберігає `tn_auth_user_id`, `cos_organization_id`, `cos_csrf_token`;
- logout invalidates native session.

### Native Cabinet

`cabinet/canonical.phtml`

- legacy `tn-portal-hero` замінено на canonical PageHeader;
- profile identity context розміщено у canonical panel shell;
- home/logout actions проходять через PageHeader/ActionBar contract;
- current user, role та organization context не змінені.

`cabinet/retired-submission.phtml`

- legacy portal-state замінено на canonical State;
- HTTP 410 ownership лишається у `CabinetPageController::retiredSubmission`;
- route `/cabinet/submission/{id}` лишається явним retired compatibility boundary.

`cabinet/index.phtml` і `cabinet/submission.phtml` не є canonical runtime renderers. Вони поки зберігаються як historical regression artifacts для WEB V0.9 і мають бути класифіковані або retired у Wave 4 audit, а не стилізовані як production surfaces.

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
- Login/Register/Cabinet використовують canonical shell primitives без зміни native auth/session contracts;
- retired cabinet submission лишається явним HTTP 410 compatibility boundary.
