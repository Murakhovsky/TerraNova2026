# Sales V0.6.1 — Workspace Completion

Sales V0.6.1 completes the updated EPIC 2 operational UX without introducing a parallel Sales runtime.

## Product contract

A manager must be able to complete the main Sales loop inside Sales Workspace:

`Lead → qualification → Deal → next action → execution/approval → result → next attention signal`.

The workspace answers three questions:

1. What needs my attention now?
2. What do we know about this lead or deal?
3. What action should happen next?

## Workspace changes

- `/sales` resolves to **Today**, the operational home for managers.
- **Today** exposes own/team scope, attention reasons, pending approvals and quick completion/rescheduling of activities.
- **Lead Inbox** supports assignment, status changes, qualification into a Deal, follow-up scheduling and an inline detail drawer.
- **Pipeline** remains configurable and uses canonical `ChangeDealStage`; it adds business filters, stage aggregates, richer cards and explicit concurrency handling.
- **Deal Workspace** adds first-class Next Action, Communications, Sales-facing approval controls and COS action lifecycle controls.
- **Director** adds current-state funnel, pipeline health, manager performance and expanded COS impact indicators.

## Architectural boundary

Sales owns the business-facing workflow and projections. Generic execution stays in Kernel:

- approval decisions use `Kernel\Approval\Service\ApprovalService`;
- proposed COS actions use `Kernel\Action\Service\ActionService` and the Kernel queue;
- stage mutation stays in `ChangeDealStage`;
- lead mutation stays in `SalesInboundService`;
- messages stay in `SalesOperationService` and the configured CRM gateway.

No Sales Rule Engine, Agent Runtime, Policy Engine, Approval Engine or queue is introduced.

## Important projection semantics

- `days_in_stage` is currently an approximation based on the Deal update timestamp until a dedicated stage-history projection is introduced.
- Director funnel figures are a **current-state cohort** for the selected period, not a historical transition funnel.
- A message operation is sent through the configured CRM provider. The local AIDA provider currently records the outbound operation; external delivery capability depends on that provider/integration.

## Regression contract

`tests/unit/sales_v061.php` protects the cross-layer contract between routes, API, Application/Kernel services, read projections and UI surfaces.

`tests/browser/sales_workspace.mjs` is an authenticated browser smoke for Today, Leads, Pipeline and Deal Workspace. It is intentionally not blocking CI until a stable authenticated browser fixture is available.
