---
title: "Закриття залишкових UI shells"
description: "PHASE 14 прибирає останні legacy shell primitives з production surfaces без знищення domain-specific interaction models."
status: active
updated: 2026-09-26
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

`symfony/templates/experience/public/property_seo.html.twig`

- PageHeader/State лишаються canonical contract із PHASE 10;
- redundant breadcrumbs видалено;
- informational SEO panel переведено на canonical panel shell;
- schema.org BreadcrumbList/ItemList, property cards, pagination та favourite hooks не змінені.

Wave 2 не намагається перетворити article body, blog cards або property cards на generic primitives.

## Хвиля 3

### Вхід та реєстрація

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

### Нативний кабінет

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

## Хвиля 4

### Фінальний audit і classification

Wave 4 закриває PHASE 14 через фактичний аудит усього `app/Interfaces/Web/View`, а не через перелік сторінок у пам’яті.

Repository-wide gate тепер забороняє повернення таких legacy shell primitives у production PHTML:

- `tn-page-hero`;
- `tn-listing-hero`;
- `tn-admin-card`;
- `tn-admin-panel`;
- `tn-portal-hero`;
- `tn-auth-copy`;
- `tn-sales-cta`;
- `tn-section-heading`.

`tn-breadcrumbs` дозволений лише для явно класифікованих Property discovery/rich-detail surfaces:

- `property/presentation.phtml`.

`property/map` більше не входить до цього whitelist: Wave 13 перевів його на Twig Map / Spatial surface. Breadcrumb whitelist зберігається лише для ще не мігрованих public rich-detail PHTML.

### Публічний SEO-лендінг

`blog/landing.phtml` був останнім live public content renderer з legacy breadcrumbs + `tn-page-hero`.

У Wave 4:

- outer shell переведено на canonical PageHeader;
- CTA переведено на canonical Panel + ActionBar;
- featured image, article body та WebPage schema.org JSON-LD збережені;
- `PublicContentPageController::guide` та route `/guide/{slug}` не змінені.

### Виведені historical renderers

Як непідключені до canonical Symfony runtime видалено:

- `cabinet/index.phtml`;
- `cabinet/submission.phtml`;
- `index/public.phtml`.

WEB V0.9 та WEB V0.10 regression gates переведені з Phalcon-era controllers/views на актуальні:

- `CabinetPageController` + `cabinet/canonical.phtml` + `cabinet/retired-submission.phtml`;
- `HomePageController` + `home/canonical.phtml`;
- `PublicContentPageController`;
- `PropertyPageController`;
- Symfony `routes.yaml`.

### Whitelist спеціалізованих surfaces (`Specialized Surface Whitelist`)

Після Wave 13 whitelist переглянутий за фактичним runtime, а не за історичним списком файлів.

**Canonical compatibility entry surfaces:**

- `auth/login.phtml`;
- `auth/register.phtml`.

Вони використовують native Symfony auth lifecycle, але ще рендеряться через compatibility PHTML layer із canonical UI partials.

**Specialized runtime surfaces:**

- `diagnostic_report/show.phtml` — структурований diagnostic report із domain-heavy report anatomy;
- `property/presentation.phtml` — rich property presentation/share runtime;
- `property/pdf.phtml` — non-web print/PDF renderer;
- `spatial/edit.phtml` — specialized Spatial editor/upload/publish workbench;
- `spatial/scene.phtml` — public 3D viewer runtime;
- `error/failure.phtml` — minimal failure utility surface.

Shared files під `components/`, `shared/` та root `index.phtml` є compatibility primitives/layouts, а не окремими production pages.

Historical whitelist entries `home/canonical.phtml` і `methodology_studio/index.phtml` більше не існують: Wave 13 перевів їх на Twig/AssetMapper. Dead legacy homepage `index/index.phtml` також видалений.

Final Wave 13 gate дозволяє лише цей явний набір page-level PHTML. Будь-який новий PHTML page поза whitelist ламає CI. Whitelist не є дозволом на довільні legacy shells: кожен виняток має конкретну runtime причину й executable contract.

## Після PHASE 14: cleanup feature assets

Після закриття PHTML shell debt окремий audit показав, що feature bundles Property і Analytics ще містили CSS для вже retired compatibility markup.

### CSS робочого простору Property

Wave 13 Phase 5 видалив dedicated `property-workspace` Vite bundle, `frontend/features/property/workspace.css` і `workspace.js`. Canonical Property Workspace використовує shared Experience Platform styles та domain `symfony/assets/styles/domains/property.css`.

### CSS робочого простору Analytics

`frontend/features/analytics/workspace.css` скорочено до:

- surface width/padding;
- живого `.tn-dashboard-bars` pattern;
- responsive layout для цього pattern.

Legacy selectors для page hero, admin metrics/dashboard cards, raw tables та table wrappers видалені.

PHASE 14 architecture gate тепер перевіряє не лише production PHTML, а й ці feature CSS bundles, щоб retired compatibility selectors не поверталися разом із майбутніми змінами.

## Після freeze: canonical public header actions

Residual primitive audit показав, що shared public header уже жив у canonical Symfony runtime, але його action cluster досі рендерив legacy `tn-btn` і приймав presentation-oriented `class => tn-btn--*` descriptors від callers.

Post-freeze cleanup закриває цей протокол:

- `shared/public_header.phtml` делегує quick actions у canonical `components/ui/action_bar.phtml`;
- callers передають semantic `variant`, а не CSS class;
- legacy `tn-btn--ghost` мапується на `variant=ghost`;
- legacy `tn-btn--dark` замінено на `variant=primary`;
- navigation, active state, public surface marker та destinations не змінені;
- WEB V0.10 gate забороняє повернення `$action['class']` і `class => tn-btn--*` у public-header callers.

Це additive cleanup presentation contract. Воно не означає, що весь specialized public UI вже має позбутися `tn-btn`; окремі rich/domain-specific surfaces можуть ще мати власні локальні actions. Закрито саме shared header boundary.

## Принцип specialized surfaces

Canonical shell не означає, що specialized application повинна перетворитися на набір стандартних cards.

Architecture graph stage, projection toolbar, filters, node details та Cytoscape interaction model залишаються domain-specific. Канонізується shell і shared primitives навколо них.

## Наступні хвилі

- Wave 2: public content shells — Page / Blog / SEO landing — виконано;
- Wave 3: auth/cabinet entry surfaces — виконано;
- Wave 4: фінальний legacy-shell audit і classification specialized marketing/runtime surfaces — виконано.

## Критерії завершення

- Architecture Explorer використовує canonical PageHeader, KPI, State та panel shell;
- live graph counters не втратили DOM hooks;
- graph/projection/health routes та manager authorization збережені;
- legacy internal shell primitives не повертаються через PHASE 14 gate;
- specialized graph interaction model залишається недоторканим;
- Page / Blog / SEO landing не використовують legacy breadcrumbs/page-hero/empty-state/section-heading shells там, де існує canonical primitive;
- article body, schema.org та property/blog content cards лишаються domain/content-specific;
- Login/Register/Cabinet використовують canonical shell primitives без зміни native auth/session contracts;
- retired cabinet submission лишається явним HTTP 410 compatibility boundary;
- historical Cabinet/Public renderers не повертаються у runtime;
- Guide landing не використовує legacy breadcrumbs/page hero/CTA shell;
- repository-wide gate забороняє legacy page/admin/portal/auth shells;
- specialized home/property/spatial/studio/failure surfaces мають явний whitelist contract;
- Property та Analytics feature CSS не містять selectors retired compatibility views.


### Заміщення Portal-контракту у Wave 13

Wave 13 Phase 7 supersedes the PHASE 14 Cabinet presentation contract. `/cabinet` and the HTTP 410 `/cabinet/submission/{id}` compatibility route now render canonical Twig Portal surfaces through Symfony AssetMapper. `cabinet/canonical.phtml`, `cabinet/retired-submission.phtml`, `portal-cabinet` Vite entrypoint and TN Portal CSS are retired. The business behavior is unchanged: managers still redirect to `/sales`, unauthenticated users to `/auth/login`, and the retired submission route remains HTTP 410.


### Публічна головна у Wave 13

Wave 13 Phase 8 переводить `/` з `HomePageController + home/canonical.phtml` на canonical Twig Public Detail / Marketing surface. Контент, SEO intent і destinations збережені; root page більше не завантажує dedicated Public Vite runtime лише заради статичного hero.


### SEO-добірки Property у Wave 13

Wave 13 Phase 8 заміщує PHASE 14 PHTML-контракт для Property SEO collections. Type, city та local landing routes тепер рендерять один canonical Twig Public Catalog surface через QueryBus; route-specific частина обмежена filter overrides, canonical URL та SEO metadata. Legacy `property/seo.phtml` видалено.


## Final Wave 13 debt closure

Compatibility shell whitelist закритий. Auth, Diagnostic Report, Property Presentation та Spatial Editor/Scene тепер належать Twig Experience Platform. `error/failure.phtml` retired як dead utility surface. Єдиний page-like PHTML виняток — `property/pdf.phtml`, класифікований як non-Web Dompdf renderer. Generic Vite entrypoints retired; важкий Spatial viewer лишається isolated island.
