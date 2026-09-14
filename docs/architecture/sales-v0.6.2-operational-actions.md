# Sales V0.6.2 — Operational Runtime

Sales V0.6.2 turns the V0.6.1 EPIC 2 mutation contracts into executable runtime operations. The visual Today/Lead/Pipeline/Deal wiring is intentionally left for the next UX commit so this change stays an atomic backend vertical slice.

## Included

- tenant-safe completion and rescheduling of Sales activities;
- canonical completion events for follow-up, task and meeting activities;
- outbound Deal messages through `SalesOperationService` and the configured CRM gateway;
- Lead status, owner, follow-up and Lead → Deal operations through `SalesInboundService`;
- Sales-facing approval decisions through Kernel `ApprovalService` with explicit `USER` approver ownership checks;
- Sales-facing COS action execute/dismiss controls through Kernel `ActionService` and the Kernel queue;
- one Sales Workspace API facade that exposes those operations without creating Sales-specific execution engines;
- restoration of a stable `SalesWorkspaceReadModelInterface` after V0.6.1 prematurely placed unfinished UX projections on the base read contract.

## Architectural boundary

Sales owns the business-facing command semantics. Kernel continues to own generic orchestration and execution:

- `ApprovalService` owns approval state transitions;
- `ActionService` owns COS action state transitions;
- `ActionExecutionJobHandler` and `JobQueueInterface` own queued execution;
- `SalesInboundService` owns Lead mutation and Lead → Deal synchronization;
- `SalesOperationService` owns Sales communication/activity operations.

No `SalesApprovalEngine`, `SalesActionEngine`, Sales queue, Sales rule engine or Sales policy engine is introduced.

## Safety and consistency rules

- Activity completion/rescheduling includes organization + deal + activity predicates, so a guessed activity id cannot cross tenant/deal boundaries.
- A `PENDING_APPROVAL` action cannot be dismissed directly. Rejection must pass through `ApprovalService` so approval and action states remain consistent.
- An approval assigned to a concrete `USER` can only be decided by that user.
- Action execution is requested through the Kernel queue, not executed synchronously from the HTTP controller.

## Deferred to Sales V0.6.3

- richer Sales read projections for communications, approvals, attention reasons and stage aggregates;
- Today quick-action controls;
- Lead Inbox action UI;
- Pipeline filters and aggregate rendering;
- Deal Communications / Approval / action-lifecycle panels;
- browser interaction wiring and UX polish.

`tests/unit/sales_v062.php` protects the runtime boundaries and route/controller/service/persistence contract introduced here.
