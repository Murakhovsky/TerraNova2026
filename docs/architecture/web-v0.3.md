# WEB V0.3 — Sales Workspace

## Goal

WEB V0.3 turns Sales into the first complete reference vertical on top of the WEB V0.2 interface foundation.

The frontend does not introduce a second Sales application layer. It projects the existing Sales V0.4 read model and commands into a coherent human workspace.

Canonical flow:

`Sales Domain -> Application use cases/read models -> Web/API interface -> Sales feature projection -> human workflow`

## Canonical Sales information architecture

```text
Sales
├── Overview
├── Today
├── Pipeline
├── Leads
├── Deals
├── Deal Workspace
├── Director
└── Sales Admin (admin only)
```

### Overview
Operational summary for the current manager: active deals, pipeline value, expected revenue, deals at risk, overdue follow-ups, fresh leads and today's focus.

### Today
Operational inbox. It deliberately contains only actionable queues returned by Sales V0.4: Must Do, AI Recommended, Overdue, New Replies, Meetings, Follow-ups and Waiting for Client.

### Pipeline
Kanban projection of configured `sales_pipelines` and `sales_pipeline_stages`. No hard-coded stage taxonomy is owned by the UI.

### Leads
Filterable projection of the canonical Sales lead read model. Lead status and qualification remain Sales-owned concepts.

### Deals
WEB V0.3 adds the missing canonical list route `/sales/deals`. It supports the existing read-model filters for search, status, pipeline, stage and high risk.

### Deal Workspace
The deal is the central Sales working surface.

It combines:
- customer and commercial context from `SalesWorkspaceReadModelInterface::deal()`;
- configured stages from `pipelines()`;
- chronological activity from `timeline()`;
- COS decision/action intelligence from `/api/sales/deals/{id}/intelligence`;
- manual stage change through the existing Sales V0.4 `/api/sales/deals/{id}/stage` command interface.

The frontend must never update stage columns directly and must not duplicate `ChangeDealStage` semantics.

### Director
Business-level portfolio and execution metrics. Runtime IDs, queues and raw event telemetry stay in COS Control Center.

### Sales Admin
Admin-only business configuration projection. Generic rules, agents, policies, approvals and audit remain generic COS/Kernel responsibilities.

## Workspace shell ownership

Sales views no longer render `shared/manager_header` themselves. `SalesController` declares:

- `workspaceSection = sales`
- `workspaceActive = <screen>`
- `pageAssetEntries = [sales-workspace]`

The root layout owns the Workspace shell. `workspaceActive` exists separately from `workspaceSection` so nested navigation can identify Today, Pipeline, Leads, Deals, Director or Sales Admin while the primary section remains Sales.

## Frontend source ownership

```text
frontend/
├── entrypoints/
│   └── sales-workspace.js
└── features/
    └── sales/
        ├── workspace.css
        └── workspace.js
```

`workspace.js` contains only browser interaction:
- load/refresh deal intelligence;
- submit stage changes to the existing API with CSRF protection;
- table-row navigation.

It does not contain business rules, stage transition policy or Sales calculations.

## Shared components used

Sales is the first vertical required to use the WEB V0.2 primitives consistently:

- PageHeader
- KpiCard
- Panel conventions
- DataTable conventions
- StatusBadge
- Tabs
- FilterBar
- Empty/Error states
- Skeleton/loading state

Future Domains should copy the structural pattern, not copy/paste Sales business UI.

## Sales V0.4 integration constraints

WEB V0.3 assumes and preserves the Sales V0.4 contracts:

- configured pipelines/stages are canonical;
- `SalesWorkspaceReadModelInterface` is the canonical read boundary;
- manual stage change uses the Sales use case through the API;
- COS intelligence is read from Operations, not reconstructed by the browser;
- AI/COS actions remain subject to Kernel Policy and Approval semantics;
- organization scope stays enforced server-side.

## Architecture checks

CI now verifies:

1. `sales-workspace` exists as a Vite entrypoint.
2. The canonical Sales navigation exists for managers.
3. Sales Admin is exposed only in admin navigation.
4. `/sales/deals` exists.
5. SalesController opts into the shared shell and feature bundle.
6. Sales PHTML views do not render their own Workspace header.
7. Sales feature source remains under `frontend/features/sales`.

## Known next steps

WEB V0.3 intentionally does not add another command layer for lead editing, task management or deal mutation beyond stage change. Those capabilities should be added only when canonical Sales application commands exist for them.

The next interface stage should build Company Home as an aggregation surface over stable Domain read contracts, with Sales V0.3 serving as the reference implementation.
