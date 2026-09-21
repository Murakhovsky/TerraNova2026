---
title: Перемикання Sales на канонічний Web Experience
description: Wave 12.26 переводить Sales Dashboard, Lead List і Lead Workspace на production routes та прибирає legacy PHTML ownership.
status: active
updated: 2026-09-22
kind: architecture
---

# Перемикання Sales на канонічний Web Experience

Wave 12.26 завершує production cutover референсного Sales vertical.

## Production routes

Після cutover:

- `/sales/dashboard` → `SalesWorkspaceController::dashboard`;
- `/sales/leads` → `SalesWorkspaceController::leads`;
- `/sales/leads/{id}` → `SalesWorkspaceController::lead`.

Окремі `/sales/reference/*` routes видалені.

## Видалене подвійне ownership

Видалено:

- `SalesReferenceController`;
- legacy `SalesPageController::dashboard()`;
- legacy `SalesPageController::leads()`;
- `app/Interfaces/Web/View/sales/dashboard.phtml`;
- `app/Interfaces/Web/View/sales/leads.phtml`;
- Twig templates з префіксом `reference_`.

Production Twig surfaces:

- `symfony/templates/experience/sales/dashboard.html.twig`;
- `symfony/templates/experience/sales/leads.html.twig`;
- `symfony/templates/experience/sales/lead_workspace.html.twig`.

## Що не змінюється

Wave 12.26 не мігрує решту Sales UI автоматично.

Поки що `SalesPageController` і PHTML залишаються власниками:

- Today;
- Pipeline;
- Deals;
- Deal Workspace;
- Director;
- Sales Administration.

Їх подальше переведення виконується окремими visual/production adoption waves.

## Архітектурна гарантія

Production Dashboard/Lead List/Lead Workspace читають дані тільки через `QueryBusInterface` та існуючі Sales Application queries.

Lead Workspace продовжує використовувати `WorkspaceCompositionResolver`, тому UIAction, mobile actions, Activity, AI context і extensions залишаються platform-owned.

Cutover gate забороняє повернення reference routes, reference controller naming і двох retired PHTML surfaces.
