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

`sales/pipeline.phtml`

- локальний filter form переходить на shared FilterBar;
- Pipeline/Owner/Risk/Priority/Value/Source/Search лишаються тими самими query params;
- Kanban і drag/drop залишаються Sales-specific pattern;
- Deal cards не перетворюються механічно на generic EntityCard, бо мають pipeline interaction semantics.

### Робочий простір угоди (`Deal Workspace`)

`symfony/templates/experience/sales/deal_workspace.html.twig`

- production route використовує `CosWorkspace` + canonical EntityHeader;
- public identity, semantic risk state, pipeline/stage/owner/value metadata стають частиною entity anatomy;
- Stimulus `sales_deal_controller.js` володіє stage/owner/follow-up/meeting/message/approval/intelligence interactions без inline JS;
- існуючі KPI, tabs, forms, approvals, communications, intelligence та timeline не змінюють behavior.


## Хвиля 2

Хвиля 2 закриває решту operational Sales surfaces, які ще використовували локальні presentation patterns.

### Список угод (`Deals`)

`sales/deals.phtml`

- локальний filter form замінено на shared FilterBar;
- raw table замінено на canonical DataTable;
- stage і risk використовують semantic presentation;
- mobile rendering переходить на record-card contract;
- query params і URL переходу в Deal Workspace збережені.

### Операційний inbox (`Today`)

`symfony/templates/experience/sales/today.html.twig`

- `/sales/today` використовує `OperationalQueue` archetype;
- вісім operational queues нормалізуються через окремий Presenter/ViewModel та canonical EntityList;
- approval, complete і reschedule mutations належать `sales_today_controller.js` і зберігають API/data-attribute contracts;
- My Work / Team scope semantics не змінені;
- legacy `sales/today.phtml` і `components/sales/today_section.phtml` видалені.

### Робочий простір директора (`Director Workspace`)

`sales/director.phtml`

- локальний toolbar замінено на shared FilterBar;
- executive currency table, historical transitions, manager performance та at-risk deals переведено на canonical DataTable;
- section shells переведено на canonical Panel;
- KPI залишаються canonical KPI cards;
- currency isolation, attribution policy і explainability semantics не змінені.


## Хвиля 3

Хвиля 3 прибирає останній окремий legacy visual shell усередині Sales Administration. Мова йде не про business behavior, а про presentation duplication: `sales-admin-page`, `sales-admin-header` і `sales-admin-card`.

### Команди та повноваження (`Teams & Authority`)

`sales_admin/teams.phtml`

- legacy admin header замінено на canonical Sales navigation + PageHeader;
- create-team і authority model розміщені у canonical panels;
- teams table переведено на canonical DataTable;
- user membership/capability forms отримують canonical fields/buttons;
- `data-create-team`, `data-membership-form`, `data-capabilities-form` збережені.

### Інтеграції (`Integrations`)

`sales_admin/integrations.phtml`

- legacy admin shell замінено на canonical workspace shell;
- provider configuration та runtime boundary використовують canonical panels;
- integration lifecycle/health використовує semantic Status;
- create/update/test/route forms зберігають існуючі JS data contracts;
- secret values як і раніше не потрапляють у Sales configuration.

### Стан та аудит (`Health & Audit`)

`sales_admin/health.phtml`

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
