---
title: Sales як референсна реалізація Web Experience
description: Канонічний вертикальний зріз Wave 12.25 для Sales Dashboard, Lead List і Lead Workspace поверх QueryBus та Web Experience Platform.
status: active
updated: 2026-09-21
kind: architecture
---

# Sales як референсна реалізація Web Experience

Wave 12.25 доводить, що Web Experience Platform працює на реальному bounded context, а не лише на dev-preview surfaces.

## Референсний зріз

Wave 12.25 спочатку перевірив vertical на паралельних reference routes. Після завершення Wave 12.26 цей самий зріз піднято на production routes:

- `/sales/dashboard` — Sales Dashboard;
- `/sales/leads` — Lead List;
- `/sales/leads/{id}` — Lead Workspace.

Reference routes після cutover видалені, щоб не лишати подвійне ownership.

## Архітектурний шлях

```text
Symfony route
  ↓
SalesWorkspaceController
  ↓
QueryBusInterface
  ↓
Sales Application Query
  ↓
Sales query handler
  ↓
Sales-owned read model
```

Web controller не залежить напряму від Sales read-model interfaces і не використовує PHTML renderer.

## Можливості платформи

Референсний vertical використовує:

- Provider-backed navigation;
- global search і command contributions від `SalesWebProvider`;
- canonical Workspace composition;
- UIAction resolution;
- mobile action placements;
- Sidebar / Activity / AI Workspace extensions;
- canonical business primitives;
- Twig SSR;
- tenant context;
- QueryBus.

## Панель продажів

Dashboard використовує наявний `GetSalesDashboardQuery` і візуалізує бізнес-факти через canonical metrics, entity list та next-action primitives.

## Список лідів

Lead List використовує `ListSalesLeadsQuery`, server-side filters і canonical `CosFilterBar` + `CosEntityListItem`.

UI не володіє правилами кваліфікації ліда.

## Робочий простір ліда

Lead Workspace використовує `GetSalesLeadQuery` і резолвить Workspace:

`sales.lead + EntityRef(sales.lead, id)`.

Таким чином actions, mobile actions, context, activity та AI slots походять із Experience Platform і `SalesWebProvider`, а не копіюються в сторінку.

## Межа Wave 12.25

Wave 12.25 не змінював Sales business rules, не створював нового read model і не дублював Search, Actions, Workspace або AI platform.

Production route cutover виконано окремою Wave 12.26. Історичний reference slice залишився архітектурним доказом, але більше не має окремих routes або controller naming.
