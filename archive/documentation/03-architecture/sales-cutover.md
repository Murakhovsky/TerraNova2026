---
title: Перемикання Sales на канонічний Web Experience
description: Wave 12.26 переводить Sales Dashboard, Lead List і Lead Workspace на production routes та прибирає legacy PHTML ownership.
status: active
updated: 2026-09-23
kind: architecture
---

# Перемикання Sales на канонічний Web Experience

Wave 12.26 завершує production cutover референсного Sales vertical.

## Production-маршрути

Після cutover:

- `/sales/dashboard` → `SalesDashboardController::index`;
- `/sales/leads` → `SalesLeadsController::index`;
- `/sales/leads/{id}` → `SalesWorkspaceController::lead`.

Окремі `/sales/reference/*` routes видалені.

## Матриця приймання Wave 12.26

| Критерій | Доказ |
| --- | --- |
| Functional parity | Lead status, owner, Lead → Opportunity і follow-up лишаються на canonical Sales commands/API; mutation-safe browser E2E перевіряє persistence після reload. |
| Permissions | Production Sales controllers вимагають manager/admin; executable access contracts перевіряють redirect і 403 до виконання QueryBus. |
| Tenant isolation | Усі reads отримують `OrganizationId` тільки з `TenantContext`; Workspace resolver звіряє organization context. |
| Mobile | Browser E2E запускає desktop і 390×844 mobile profiles; UIAction provider має `MOBILE_PRIMARY` і `MOBILE_MENU`. |
| Performance | Authenticated Sales E2E застосовує budgets для navigation time, transfer bytes та DOM size на Dashboard, Lead List і Lead Workspace. |
| Realtime | Production surfaces успадковують canonical `workspace_shell` із realtime connection state та `cos:realtime-update`. |
| Agent integration | `SalesWebProvider` публікує AI Workspace slot та `AI_PROPOSAL` action placement; AI context працює з тим самим `EntityRef` і governed actions. |
| Audit | Mutations несуть correlation/idempotency; Sales write/follow-up flows публікують `EventMetadata`, а Action/Approval flows проходять через Kernel audit-enabled services. |

Після проходження цієї матриці retired reference/PHTML ownership видаляється.

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

## Збереження операційної поведінки

Cutover не перетворює Lead Inbox на read-only surface.

Канонічний Twig/Stimulus шар зберігає:

- зміну Lead status через `PATCH /api/v1/sales/leads/{id}`;
- призначення owner через той самий canonical update endpoint;
- Lead → Opportunity через `POST /api/v1/sales/leads/{id}/opportunity`;
- створення follow-up через `POST /api/v1/sales/leads/{id}/followups`;
- CSRF та idempotency headers.

Список доступних owner читається через `SalesAdminQuery('team.users')` у QueryBus, а не через пряму залежність Web controller на Sales admin service.

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
