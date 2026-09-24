---
title: Wave 13 — Трекер візуальної міграції
description: Поточний стан production migration units COS Visual Rebuild із окремим відстеженням archetype, Twig cutover і legacy cleanup.
status: active
updated: 2026-09-23
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
| VR-013 | `/property/manage` | Workspace | Property | Collection | P0 | Twig | QA |
| VR-014 | `/property/listing` | Workspace | Property | Collection | P0 | Twig | QA |
| VR-015 | `/property/submissions` | Workspace | Property | Operational Queue | P0 | Twig | QA |
| VR-016 | `/property/submission/{id}` | Workspace | Property | Entity Workspace | P0 | Twig | QA |
| VR-017 | `/property/map` | Workspace/Public | Property | Map / Spatial | P0 | Twig | BACKLOG |
| VR-018 | `/spatial/manage` | Workspace | Property / Spatial | Map / Spatial | P0 | Twig | BACKLOG |

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
