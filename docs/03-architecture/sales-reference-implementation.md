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

Паралельні маршрути до cutover:

- `/sales/reference/dashboard` — Sales Dashboard;
- `/sales/reference/leads` — Lead List;
- `/sales/reference/leads/{id}` — Lead Workspace.

Старі `/sales/*` маршрути не видаляються у цій хвилі. Їх перемикання належить Wave 12.26.

## Архітектурний шлях

```text
Symfony route
  ↓
SalesReferenceController
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

## Platform capabilities

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

## Sales Dashboard

Dashboard використовує наявний `GetSalesDashboardQuery` і візуалізує бізнес-факти через canonical metrics, entity list та next-action primitives.

## Lead List

Lead List використовує `ListSalesLeadsQuery`, server-side filters і canonical `CosFilterBar` + `CosEntityListItem`.

UI не володіє правилами кваліфікації ліда.

## Lead Workspace

Lead Workspace використовує `GetSalesLeadQuery` і резолвить Workspace:

`sales.lead + EntityRef(sales.lead, id)`.

Таким чином actions, mobile actions, context, activity та AI slots походять із Experience Platform і `SalesWebProvider`, а не копіюються в сторінку.

## Межа Wave 12.25

Ця хвиля не:

- видаляє старі Sales PHTML templates;
- перемикає production `/sales/dashboard` або `/sales/leads`;
- змінює Sales business rules;
- створює новий Sales read model;
- дублює Search, Actions, Workspace або AI platform.

Production route cutover і видалення старого UI належать Wave 12.26.
