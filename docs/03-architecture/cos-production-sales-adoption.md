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

`sales/dashboard.phtml`

- PageHeader залишається canonical;
- KPI використовують canonical KPI cards;
- New Leads переходить з локальної HTML table на canonical DataTable;
- status отримує semantic tone;
- mobile table використовує record-card contract.

### Вхідні ліди (`Lead Inbox`)

`sales/leads.phtml`

- PageHeader залишається canonical;
- filter form переходить на shared FilterBar;
- status label переходить на semantic Status;
- workflow buttons та JS data attributes не змінюються;
- lead detail drawer behavior не переписується в цій хвилі.

### Воронка продажів (`Sales Pipeline`)

`sales/pipeline.phtml`

- локальний filter form переходить на shared FilterBar;
- Pipeline/Owner/Risk/Priority/Value/Source/Search лишаються тими самими query params;
- Kanban і drag/drop залишаються Sales-specific pattern;
- Deal cards не перетворюються механічно на generic EntityCard, бо мають pipeline interaction semantics.

### Робочий простір угоди (`Deal Workspace`)

`sales/deal.phtml`

- звичайний PageHeader замінено на EntityHeader;
- public identity, semantic risk state, pipeline/stage/owner/value metadata стають частиною entity anatomy;
- існуючі KPI, tabs, forms, approvals, communications, intelligence та timeline не змінюють behavior.

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
- query names та mutation data attributes збережені;
- не повертаються локальні дублікати table/filter/entity-header patterns;
- PHTML syntax зелений;
- production Vite build зелений;
- PHASE 0–8 gates залишаються зеленими;
- окремий PHASE 9 architecture gate захищає adoption від regression.
