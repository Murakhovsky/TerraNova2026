---
title: Wave 13 — Трекер візуальної міграції
description: Поточний стан production migration units COS Visual Rebuild із окремим відстеженням archetype, Twig cutover і legacy cleanup.
status: closed
updated: 2026-09-26
kind: architecture
---

# Wave 13 — Трекер візуальної міграції

## Поточний стан

| ID | Route | Surface | Domain | Archetype | Priority | Target | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| VR-001 | `/admin` | Workspace | Core | Executive Dashboard | P0 | Twig | DONE |
| VR-002 | `/sales/dashboard` | Workspace | Sales | Domain Dashboard | P0 | Twig | DONE |
| VR-003 | `/sales/leads` | Workspace | Sales | Collection | P0 | Twig | DONE |
| VR-004 | `/sales/deals/{id}` | Workspace | Sales | Entity Workspace | P0 | Twig | DONE |
| VR-005 | `/sales/today` | Workspace | Sales | Operational Queue | P0 | Twig | DONE |
| VR-006 | `/sales/pipeline` | Workspace | Sales | Process / Pipeline | P0 | Twig | DONE |
| VR-007 | `/sales/deals` | Workspace | Sales | Collection | P0 | Twig | DONE |
| VR-008 | `/sales/director` | Workspace | Sales | Executive Dashboard | P0 | Twig | DONE |
| VR-009 | `/sales/admin/*` | System | Sales | System / Control Surface | P0 | Twig | DONE |
| VR-010 | `/client-case/inbox` | Workspace | Sales / Clients | Operational Queue | P0 | Twig | DONE |
| VR-011 | `/client-case` | Workspace | Sales / Clients | Collection | P0 | Twig | DONE |
| VR-012 | `/client-case/show/{id}` | Workspace | Sales / Clients | Entity Workspace | P0 | Twig | DONE |
| VR-013 | `/property/manage` | Workspace | Property | Collection | P0 | Twig | DONE |
| VR-014 | `/property/listing` | Workspace | Property | Collection | P0 | Twig | DONE |
| VR-015 | `/property/submissions` | Workspace | Property | Operational Queue | P0 | Twig | DONE |
| VR-016 | `/property/submission/{id}` | Workspace | Property | Entity Workspace | P0 | Twig | DONE |
| VR-017 | `/property/map` | Public | Property | Map / Spatial | P0 | Twig | DONE |
| VR-018 | `/spatial/manage` | Workspace | Property / Spatial | Map / Spatial | P0 | Twig | DONE |
| VR-019 | `/cos/control-center` | System | COS | System / Control Surface | P0 | Twig | DONE |
| VR-020 | `/cos/architecture` | System | COS / Visualization | System / Control Surface | P0 | Twig + JS Island | DONE |
| VR-021 | `/admin/diagnostics/methodology-studio` | System | Diagnostic | System / Control Surface | P0 | Twig + Specialized Island | DONE |
| VR-022 | `/admin/analytics` | System | Core / Property | Executive Dashboard | P0 | Twig | DONE |
| VR-023 | `/admin/users` | System | Identity | System / Control Surface | P0 | Twig | DONE |
| VR-024 | `/admin/content` | System | Content | System Control + Form Editor | P0 | Twig | DONE |
| VR-025 | `/cabinet` | Portal | Identity | Portal | P0 | Twig | DONE |
| VR-026 | `/cabinet/submission/{id}` | Portal | Compatibility | Portal | P0 | Twig | DONE |
| VR-027 | `/` | Public | Core | Public Detail / Marketing | P0 | Twig | DONE |
| VR-028 | `/property/catalog` | Public | Property | Public Catalog | P0 | Twig | DONE |
| VR-029 | `/property/show/{slug}` | Public | Property | Public Detail / Marketing | P0 | Twig + Gallery Island | DONE |
| VR-030 | `Property SEO collections` | Public | Property | Public Catalog | P0 | Twig | DONE |
| VR-031 | `/property/favour` | Public | Property | Public Catalog | P0 | Twig | DONE |
| VR-032 | `/property/submit` | Public | Property | Form / Editor | P0 | Twig | DONE |
| VR-033 | `/terra-nova` | Public | Brand | Public Detail / Marketing | P0 | Twig | DONE |
| VR-034 | `/agency` | Public | Brand | Public Detail / Marketing | P0 | Twig | DONE |
| VR-035 | `/services` | Public | Brand | Public Detail / Marketing | P0 | Twig | DONE |
| VR-036 | `/partners` | Public | Brand | Public Detail / Marketing | P0 | Twig | DONE |
| VR-037 | `/team` | Public | Brand | Public Detail / Marketing | P0 | Twig | DONE |
| VR-038 | `/cases` | Public | Brand | Public Detail / Marketing | P0 | Twig | DONE |
| VR-039 | `/vacancies` | Public | Brand | Public Detail / Marketing | P0 | Twig | DONE |
| VR-040 | `/contacts` | Public | Brand / Sales | Public Detail / Marketing + Form | P0 | Twig | DONE |
| VR-041 | `/it` | Public | Brand | Public Detail / Marketing | P0 | Twig | DONE |
| VR-042 | `/art` | Public | Brand | Public Detail / Marketing | P0 | Twig | DONE |
| VR-043 | `/blog` | Public | Content | Public Catalog | P0 | Twig | DONE |
| VR-044 | `/blog/{slug}` | Public | Content | Public Detail / Marketing | P0 | Twig | DONE |
| VR-045 | `/guide/{slug}` | Public | Content | Public Detail / Marketing | P0 | Twig | DONE |
| VR-046 | `/cos` + `/cos/{lang}` | Public | COS | Public Detail / Marketing | P0 | Twig | DONE |
| VR-047 | `/cos/{lang}/domains/{slug}` | Public | COS | Public Detail / Marketing | P0 | Twig | DONE |

VR-001 завершений і змерджений у `main`: `/admin` більше не має legacy PHTML ownership або page-specific Vite entrypoint.

VR-002 уже нормалізований як Domain Dashboard: окремий ViewModel/Presenter, PagePresentation contract, PageHeader і реальний KpiStrip без нового page-specific CSS.

VR-003 використовує Collection archetype з `EntityList + FilterBar` як валідною альтернативою `DataGrid + Toolbar`. Це зафіксовано executable required pattern groups, а не локальним винятком для Sales.

VR-004 завершений: Deal Workspace працює через `CosWorkspace + EntityHeader`, окремий ViewModel/Presenter і Stimulus runtime; legacy PHTML ownership видалено.

Phase 2.5 консолідує Golden Four і вводить вибіркову stability policy: стабілізуються лише чотири доведені archetypes та мінімальний Pattern core, решта Visual System лишається experimental.

VR-005 переводить Sales Today на Operational Queue: Query → Presenter/ViewModel → Twig + Stimulus, зі збереженням approval/complete/reschedule API contracts.

## Зменшення legacy

Стартовий Wave 13 baseline для canonical Symfony visual layer:

- нові `tn-*` primitives: 0;
- inline browser handlers: 0;
- canonical inline-style debt: не більше 12.

VR-001 додатково видаляє один production PHTML screen і один page-specific frontend entrypoint.


VR-006 переводить Sales Pipeline на Process / Pipeline archetype: canonical PageHeader/Toolbar/FilterBar обрамляють Sales-specific Kanban; drag/drop збережено, а keyboard stage mutation додано як accessibility parity.

VR-007 переводить Deals на canonical Collection/DataGrid. Generic DataGrid filter отримав text-filter contract, а operational Sales read model — offset pagination без зміни filter semantics.

VR-008 переводить Sales Director на Executive Dashboard: Domain cockpit лишається source of truth, а currency/transition/manager/risk projections рендеряться canonical DataGrid-ами. `SalesPageController` повністю видалено.

VR-009 стартував як route-family cutover `/sales/admin/*`. Перший цикл переводить dashboard на System / Control Surface та централізує read boundary через `SalesAdminQuery`; legacy підсторінки залишаються доступними до наступних циклів міграції.

VR-009 Cycle B переводить Pipelines/Rules/Agents/Actions/Teams/Integrations/Health на canonical System Control Surface + Stimulus. Після цього legacy Sales Admin PHTML лишається тільки для трьох detail editors: Pipeline, Rule, Agent.

VR-009 Cycle C переводить Pipeline і Agent detail editors на canonical Twig + Stimulus. Legacy `frontend/features/sales/admin.js` видалено; єдиний PHTML surface у Sales — Rule editor.

VR-009 Cycle D переводить Rule editor на canonical Twig + Stimulus і видаляє `SalesAdminPageController`, `sales-workspace` Vite entrypoint та весь legacy Sales frontend source. Sales visual PHTML burn-down після цього циклу: **0**.


## Фаза 3 — завершення Sales

Sales production visual migration units VR-005…VR-009 завершені.

- regular Sales routes використовують canonical Symfony/Twig archetypes;
- Sales Admin route family використовує System / Control Surface;
- Sales visual PHTML = **0**;
- legacy `SalesPageController` = **0**;
- legacy `SalesAdminPageController` = **0**;
- legacy `frontend/features/sales/*` = **0**;
- legacy `sales-workspace` Vite entrypoint = **0**.

Наступна production migration family: **Phase 4 — Clients**.


VR-010 переводить Client Case Inbox на Operational Queue без створення окремого Clients Domain: read path проходить через Sales Application Query, triage cards лишаються domain component, mutation forms працюють server-first через існуючі Sales commands.


VR-011 переводить Client Case Collection на стабільний Collection contract `EntityList + FilterBar`. Editable quick-update row і funnel лишаються Sales/Client Case domain components; generic DataGrid не отримує mutation semantics, яких у нього немає.


VR-012 переводить Client Case Workspace на повний `CosWorkspace` runtime через `sales.client_case`. Entity лишається `sales.deal`, тому governed Sales UIActions та extension slots перевикористовуються без нового business Domain. Legacy `ClientCasePageController`, `client_case/show.phtml` і `clients-workspace` frontend bundle видаляються.


## Фаза 4 — завершення Clients

Production migration units VR-010…VR-012 завершені.

- `/client-case/inbox` використовує canonical Operational Queue;
- `/client-case` використовує canonical Collection;
- `/client-case/show/{id}` використовує canonical Entity Workspace;
- Clients лишається presentation family поверх Sales, окремого `Domains\\Clients` немає;
- Client Case visual PHTML = **0**;
- legacy `ClientCasePageController` = **0**;
- legacy `clients-workspace` Vite entrypoint = **0**;
- legacy Clients workspace CSS = **0**;
- mutation ownership централізований у `ClientCaseMutationController`.

Наступна production migration family: **Phase 5 — Property Workspace**.


VR-013/014 переводять Property Inventory і Listing на один canonical Collection/DataGrid runtime з реальним server-side offset/total pagination. Read ownership проходить через Application Query; legacy `workspace_canonical.phtml` видаляється.


VR-015 переводить Property Submissions на Operational Queue: KPI показують навантаження moderation, EntityList — записи для рішення, а pagination працює server-side через Property read model.


VR-016 переводить Property Submission у read-only Entity Workspace `property.submission`. Intake entity не маскується під Property Asset до моменту фактичного створення asset; legacy `submission_canonical.phtml` видаляється.


VR-017 переводить публічну Property Map на Map / Spatial archetype без зміни access semantics. Координати й нормалізовані map positions формуються у Presenter/ViewModel; Stimulus лише застосовує DOM-positioning, тому inline visual CSS видалено.


VR-018 переводить Spatial Manage на Map / Spatial archetype через Application Query та canonical Workspace shell. Spatial editor/upload/publish/public viewer лишаються окремими живими сценаріями і не змішуються з цією route unit.


## Фаза 5 — завершення Property Workspace

Production migration units VR-013…VR-018 завершені.

- `/property/manage` і `/property/listing` → Collection/DataGrid;
- `/property/submissions` → Operational Queue;
- `/property/submission/{id}` → Entity Workspace;
- `/property/map` → public Map / Spatial без зміни access semantics;
- `/spatial/manage` → private Map / Spatial;
- Property workspace compatibility PHTML для цих units = **0**;
- dedicated `property-workspace` Vite bundle = **0**;
- map pin inline visual CSS = **0**;
- Spatial editor/mutations/public viewer залишаються окремими specialized сценаріями.

Наступна production migration family: **Phase 6 — System / Admin UI**.


## Фаза 6 — System / Admin UI

VR-019 переводить COS Control Center з PHTML compatibility runtime на canonical System / Control Surface: Application Query → Presenter/ViewModel → Twig, з server-first execution/approval forms і без page-specific Vite bundle.

VR-020 переводить Architecture Explorer на canonical System / Control Surface + Stimulus island. Graph projections/health читаються через Application Query, повний graph payload більше не вбудовується в HTML, legacy PHTML/Vite/TN runtime видалено.

VR-021 переводить Methodology Studio на canonical System / Control Surface + specialized AssetMapper island. V0.5.3/0.5.4/0.5.5 editor behavior та API contracts збережені; PHTML/Vite ownership видалено, editor CSS переведено на COS tokens і canonical breakpoints.

VR-022 переводить Administration Analytics на read-only Executive Dashboard: Application Query → Presenter/ViewModel → KPI + canonical DataGrid/EntityList. Page-specific Vite bundle видалено.

VR-023 переводить Users Administration на canonical System / Control Surface. Reads і create/update mutations проходять через Query/Command Bus; старий CoreWorkspace PHTML controller та users.phtml видалено.

VR-024 переводить Content Administration listing на System / Control Surface, editor — на Form Editor, save — через CommandBus. `content/manage.phtml` і `content/edit.phtml` видалено.


## Фаза 6 — завершення System / Admin UI

Production migration units VR-019…VR-024 завершені.

- `/cos/control-center` → canonical System / Control Surface;
- `/cos/architecture` → System / Control Surface + specialized Architecture island;
- `/admin/diagnostics/methodology-studio` → System / Control Surface + specialized Methodology island;
- `/admin/analytics` → Executive Dashboard;
- `/admin/users` → System / Control Surface;
- `/admin/content` → System / Control Surface + Form Editor;
- production PHTML ownership для VR-019…024 = **0**;
- page-specific Vite entrypoints для VR-019…024 = **0**;
- direct read composition у Web controllers для цих units = **0**;
- specialized browser runtimes залишені лише для Architecture Explorer і Methodology Studio.

Наступна production migration family: **Phase 7 — Portal**.


## Фаза 7 — Portal

VR-025 переводить Cabinet home з PHTML/Vite presentation на canonical Portal archetype: TenantContext → Presenter/ViewModel → Twig. Manager redirect /sales і unauthenticated redirect /auth/login збережені.


VR-026 зберігає `/cabinet/submission/{id}` як явний HTTP 410 compatibility boundary, але переносить його на canonical Portal Twig без PHTML/Vite runtime.

## Фаза 7 — завершення Portal

VR-025…026 завершені. Cabinet home і retired submission працюють через Symfony AssetMapper + Portal archetype; Portal PHTML = 0; dedicated `portal-cabinet` Vite/CSS = 0; окремий Portal DDD Domain не створено. Наступна family: Phase 8 — Public Property.


## Фаза 8 — Public Property

VR-027 переводить root Public surface з PHTML/Public Vite ownership на Twig Public Detail / Marketing archetype. Контент і destinations не змінюються; `public-surface` Vite залишається живим для ще не мігрованих Property public routes.


VR-028 переводить Public Property Catalog на QueryBus/CommandBus + Public Catalog archetype. Favourites залишаються browser-side projection через існуючий API, inbound lead проходить через ReceivePublicLeadCommand; legacy `property/catalog.phtml` видаляється.

VR-029 переводить Public Property Detail на QueryBus/CommandBus + Public Detail / Marketing archetype. Gallery працює через Stimulus, favourites перевикористовують public-property controller, view analytics — окремий Application Command; legacy `property/show.phtml` та Vite gallery entrypoint видаляються.

VR-030 переводить Property SEO collections на один canonical Public Catalog runtime. Type/City/landing routes відрізняються лише filter overrides та SEO metadata; inventory/cards/pagination не дублюються.

VR-031 переводить Favourites на session IDs → canonical public read-port → Twig cards. Старий рендер до 150 карток з browser-side hiding видалено; API toggle contract збережено.

VR-032 переводить Public Property Submit та aliases на canonical Form / Editor. Write parity свідомо збережена: POST повертає HTTP 503 і не створює запис, доки public intake не матиме окремого Application Command.


## Фаза 8 — завершення Public Property

Production migration units VR-027…032 завершені.

- root Public surface працює через Symfony/Twig Public Detail / Marketing;
- `/property` і `/property/catalog` використовують один canonical Public Catalog runtime;
- `/property/show/{slug}` працює через QueryBus/CommandBus + Public Detail / Marketing + Stimulus gallery;
- type/city/local SEO collections перевикористовують Catalog Query/ViewModel/cards/pagination;
- `/property/favour` читає лише session-selected `public_id` через вузький public read-port;
- `/property/submit`, `/property/create`, `/submit-property` використовують один Form / Editor runtime;
- public submit POST свідомо лишається HTTP 503 без persistence до появи окремого public-intake Application Command;
- legacy PHTML для VR-027…032 = **0**;
- legacy `terranova-catalog-api` і `terranova-property-gallery` Vite source entrypoints = **0**.

`/property/presentation/{slug}` і `/property/pdf/{slug}` не входять у VR-027…032. Вони залишаються спеціалізованим compatibility runtime до фінальної хвилі legacy deletion і не вважаються canonical Public Property visual surface.

Наступна production migration family: **Phase 9 — Public Brand**.

## Фаза 9 — Public Brand

VR-033…042 переводять десять статичних brand destinations із orphaned PHTML shell на один canonical Symfony runtime. Page definitions лишаються в `PublicPageCatalog`; Web читає їх через `GetPublicBrandPageQuery`, Presenter/ViewModel готують presentation state, а Twig використовує Public Detail / Marketing archetype без десяти локальних page stacks.

`/contacts` зберігає робочий inbound lead flow через `ReceivePublicLeadCommand`. Решта brand pages є read-only public projections із canonical CTA до каталогу, submit та contact surfaces.

## Фаза 9 — завершення Public Brand

Production migration units VR-033…042 завершені.

- усі 10 routes явно належать Symfony;
- один Query/Presenter/ViewModel/Twig runtime обслуговує всю brand family;
- `/contacts` не обходить Application Command boundary;
- Public Brand використовує canonical PageHeader, Card, ActionBar та form components;
- page-specific Public Brand CSS/JS = **0**;
- legacy `app/Interfaces/Web/View/page/show.phtml` = **0**;
- нові `tn-*` primitives = **0**.

Наступна production migration family: **Phase 10 — Content**.

## Фаза 10 — Content

VR-043 переводить `/blog` на canonical Public Catalog: Application Query → Presenter/ViewModel → Twig, із server-side pagination та empty state без PHTML ownership.

VR-044 переводить `/blog/{slug}` на Public Detail / Marketing: published article читається через QueryBus, metadata/schema формуються у Presenter/ViewModel, article body лишається керованим Content payload.

VR-045 переводить `/guide/{slug}` на той самий canonical Public Detail / Marketing runtime для SEO landing content без окремого frontend stack.

## Фаза 10 — завершення Content

Production migration units VR-043…045 завершені.

- `/blog` → canonical Public Catalog;
- `/blog/{slug}` і `/guide/{slug}` → canonical Public Detail / Marketing;
- public Content reads проходять через Application QueryBus;
- canonical Twig templates не мають `tn-*`, inline style або browser handlers;
- legacy `app/Interfaces/Web/View/blog/index.phtml` = **0**;
- legacy `app/Interfaces/Web/View/blog/show.phtml` = **0**;
- legacy `app/Interfaces/Web/View/blog/landing.phtml` = **0**;
- page-specific Content JS/CSS = **0**.

Наступна production migration family: **Phase 11 — Public COS**.

## Фаза 11 — Public COS

VR-046 повертає public COS landing у Symfony route ownership і переносить пʼять мовних версій на canonical Public Detail / Marketing runtime. Multilingual product/domain catalog винесений із колишнього Phalcon controller у read-only Application catalog.

VR-047 переводить 21 domain presentation на той самий canonical runtime: QueryBus → Presenter/ViewModel → Twig. Domain pages зберігають локалізовані titles, outcomes, signals, industry labels та featured/spotlight presentations без власного frontend stack.

## Фаза 11 — завершення Public COS

Production migration units VR-046…047 завершені.

- `/cos` і `/cos/{lang}` → canonical Public Detail / Marketing;
- `/cos/{lang}/domains/{slug}` → canonical Public Detail / Marketing;
- підтримані `en/de/fr/pl/uk`;
- збережений 21-domain public catalog;
- old CompanyOsController data перенесено у read-only `PublicCosCatalog`;
- legacy `company_os/*.phtml` = **0**;
- dedicated `cos-site` Vite source/CSS = **0**;
- нові `tn-*` primitives = **0**.

Основний план Wave 13 для production page families 3–11 закритий. Далі — фінальний Wave 13 audit: specialized compatibility surfaces, dead frontend/runtime artifacts, regression gates і branch integration.


## Wave 13 — фінальне закриття

Wave 13 закритий після repository-wide audit production page ownership.

- VR-001…VR-047 мають статус `DONE`;
- production families 3–11 належать canonical Symfony/Twig Experience Platform;
- нові production page-level PHTML заборонені final-audit gate;
- PHTML, що лишився, класифікований або як shared compatibility primitive/layout, або як явний specialized runtime;
- dead legacy homepage `app/Interfaces/Web/View/index/index.phtml` видалений;
- retired page-specific Vite source entrypoints не повертаються;
- Wave 13 CI запускається для всіх `visual/**` branches і для PR у `main`;
- фінальна інтеграція виконується одним PR із верхівки stacked migration chain.

Подальший visual development не продовжує Wave 13. Нові зміни мають відбуватись поверх canonical Experience Platform і не можуть відновлювати retired route/view ownership.


## Wave 13 — 100% debt closure

Фінальний debt-closure cycle закрив compatibility whitelist, який лишався після VR-001…VR-047:

- Auth login/register → canonical Twig Form Editor;
- Diagnostic Report → Application Query + Twig System surface;
- Property Presentation → Query/Command + Twig Public Detail runtime;
- Public Property Submit → canonical CommandBus write замість HTTP 503 placeholder;
- Spatial Editor → Twig + AssetMapper specialized workbench;
- Spatial public scene → Twig shell + isolated Vite/Three.js island;
- legacy PHTML renderer → **0** у Web runtime;
- production page-level PHTML → **0**;
- `property/pdf.phtml` лишається виключно non-Web service print renderer для Dompdf;
- generic/page Vite entrypoints → **0**;
- Vite лишається тільки для важкого `spatial-viewer` island;
- canonical Web JS/CSS належать Symfony AssetMapper та `symfony/assets/**`.

Wave 13 Master Specification виконана повністю на production runtime ownership level.
