---
title: Впровадження канонічного UI у production Sales
description: PHASE 9 контрольованої visual migration COS на першій reference vertical без зміни Sales business behavior.
status: active
updated: 2026-09-21
kind: architecture
---

# Впровадження канонічного UI у production Sales — PHASE 9

## Мета

PHASE 9 переносить канонічну visual language з `/dev/ui`, `/dev/workspace` та `/dev/stress` на живі business screens.

Першою вертикаллю лишається Sales, бо вона вже визначена як reference vertical архітектури COS.

Міграція не змінює:

- Sales read models;
- command semantics;
- URL contracts;
- CSRF;
- drag-and-drop pipeline behavior;
- lead qualification behavior;
- intelligence loading;
- tenant/permission boundaries.

Змінюється лише presentation composition.

## Сумісний перехідний шар

Production Sales поки рендериться через Symfony-owned PHTML compatibility surface та Vite bundle. Тому PHASE 9 не намагається вставляти Twig Components у PHTML.

До остаточного Twig cutover дозволений один compatibility bridge:

```text
Sales read model
→ existing PHTML controller/view
→ canonical PHTML UI contract
→ COS Vite design system
```

Це не друга design system. PHTML components мають повторювати canonical anatomy і поступово зникнуть разом із compatibility renderer.

## Хвиля 1

### Панель продажів (`Sales Dashboard`)

`symfony/templates/experience/sales/dashboard.html.twig` після Wave 12.26.

Початкова PHASE 9 міграція використовувала PHTML compatibility layer. Після canonical Sales cutover production ownership перейшов до Twig:

- canonical `CosEntityHeader`;
- `CosMoneyMetric` / `CosTrendMetric`;
- canonical entity list;
- server-owned Sales query projection;
- production route `/sales/dashboard`.

### Вхідні ліди (`Lead Inbox`)

`symfony/templates/experience/sales/leads.html.twig` та `lead_workspace.html.twig` після Wave 12.26.

Початкова PHASE 9 міграція зберігала PHTML interaction hooks. Після cutover ці ж операційні контракти перенесені у Twig + Stimulus:

- `CosFilterBar`;
- semantic Status;
- status / owner / create deal / follow-up mutations;
- canonical `/sales/leads/{id}` Lead Workspace;
- CSRF та idempotency contracts.

### Воронка продажів (`Sales Pipeline`)

`symfony/templates/experience/sales/pipeline.html.twig`

- canonical `CosToolbar` і `CosFilterBar` володіють shared controls;
- Pipeline/Owner/Risk/Priority/Value/Source/Search лишаються тими самими query params;
- Kanban живе як Sales-specific `SalesPipelineBoard`, а не fake generic table;
- drag/drop збережено у `sales_pipeline_controller.js`;
- keyboard stage select дає ту саму mutation без мишки.

### Робочий простір угоди (`Deal Workspace`)

`symfony/templates/experience/sales/deal_workspace.html.twig`

- production route використовує `CosWorkspace` + canonical EntityHeader;
- public identity, semantic risk state, pipeline/stage/owner/value metadata стають частиною entity anatomy;
- Stimulus `sales_deal_controller.js` володіє stage/owner/follow-up/meeting/message/approval/intelligence interactions без inline JS;
- існуючі KPI, tabs, forms, approvals, communications, intelligence та timeline не змінюють behavior.


## Хвиля 2

Хвиля 2 закриває решту operational Sales surfaces, які ще використовували локальні presentation patterns.

### Список угод (`Deals`)

`symfony/templates/experience/sales/deals.html.twig`

- Collection використовує canonical `CosDataGrid` з search/filter/columns/pagination/mobile-card contract;
- generic DataGrid filter підтримує select і text inputs без Sales-specific логіки;
- legacy flat filter query params лишаються backward-compatible aliases;
- Open row action веде в canonical Deal Workspace;
- operational read model підтримує offset server pagination.

### Операційний inbox (`Today`)

`symfony/templates/experience/sales/today.html.twig`

- вісім operational queues збираються через canonical Operational Queue composition;
- queue items використовують `CosEntityListItem`, а швидкі дії — `CosActionBar`;
- approval, complete і reschedule data attributes збережені;
- My Work / Team scope semantics не змінені.

### Робочий простір директора (`Director Workspace`)

`symfony/templates/experience/sales/director.html.twig`

- `SalesDirectorCockpitService` лишається source of truth через Application Query;
- filters рендеряться canonical `CosFilterBar`;
- executive currency, historical transitions, manager performance та at-risk deals використовують `CosDataGrid`;
- KPI використовують canonical KpiStrip;
- currency isolation, attribution policy і explainability semantics не змінені;
- старий `SalesPageController` видалено після завершення останнього PHTML-owned Sales surface.


## Хвиля 3

Хвиля 3 прибирає останній окремий legacy visual shell усередині Sales Administration. Мова йде не про business behavior, а про presentation duplication: `sales-admin-page`, `sales-admin-header` і `sales-admin-card`.

### Команди та повноваження (`Teams & Authority`)

`symfony/templates/experience/sales/admin/teams.html.twig`

- legacy admin header замінено на canonical Sales navigation + PageHeader;
- create-team і authority model розміщені у canonical panels;
- teams table переведено на canonical DataTable;
- user membership/capability forms отримують canonical fields/buttons;
- `data-create-team`, `data-membership-form`, `data-capabilities-form` збережені.

### Інтеграції (`Integrations`)

`symfony/templates/experience/sales/admin/integrations.html.twig`

- legacy admin shell замінено на canonical workspace shell;
- provider configuration та runtime boundary використовують canonical panels;
- integration lifecycle/health використовує semantic Status;
- create/update/test/route forms зберігають існуючі JS data contracts;
- secret values як і раніше не потрапляють у Sales configuration.

### Стан та аудит (`Health & Audit`)

`symfony/templates/experience/sales/admin/health.html.twig`

- overall/subsystem health подано через canonical KPI cards;
- issue та runtime states використовують semantic Status;
- runtime queues, audit, configuration revisions та metrics переведено у canonical panel hierarchy;
- read-only semantics не змінено.

PHASE 9 gate тепер прямо забороняє повернення legacy `sales-admin-page`, `sales-admin-header` та `sales-admin-card` у цих трьох production views.

## Розширення FilterBar

Compatibility FilterBar отримує:

- hidden query fields;
- `number` input;
- `min/max/step`.

Розширення generic і не містить Sales semantics.

## Правило локальної специфіки

PHASE 9 не видаляє Sales-specific patterns, якщо вони справді несуть domain interaction:

- Kanban stage drop zones;
- lead qualification controls;
- communication composer;
- intelligence payloads;
- approval flows.

Canonical layer стандартизує повторювану anatomy. Domain layer лишає специфічну взаємодію.

## Критерії завершення

Хвиля PHASE 9 завершена, коли:

- Dashboard використовує canonical DataTable;
- Leads використовує canonical FilterBar і Status;
- Pipeline використовує canonical FilterBar;
- Deal використовує canonical EntityHeader;
- Deals використовує canonical FilterBar + DataTable;
- Today використовує canonical Panel composition;
- Director використовує canonical FilterBar + Panel + DataTable;
- Teams, Integrations і Health більше не використовують окремий legacy Sales Admin visual shell;
- query names та mutation data attributes збережені;
- не повертаються локальні дублікати table/filter/entity-header patterns;
- PHTML syntax зелений;
- production Vite build зелений;
- PHASE 0–8 gates залишаються зеленими;
- окремий PHASE 9 architecture gate захищає adoption від regression.


## Завершення Wave 13 Phase 3

Sales presentation ownership is now canonical Symfony/Twig/Stimulus end to end.

- `/sales/today` → Operational Queue;
- `/sales/pipeline` → Process / Pipeline;
- `/sales/deals` → Collection / DataGrid;
- `/sales/director` → Executive Dashboard;
- `/sales/admin/*` → System / Control Surface;
- Sales-specific drag/drop remains a domain component;
- Rule, Pipeline, Agent, Teams, Integrations and Policy administration use dedicated Stimulus controllers;
- legacy `SalesPageController` and `SalesAdminPageController` are retired;
- legacy `sales-workspace` Vite entrypoint is retired;
- **Sales visual PHTML = 0**.

