# Sales V0.6.3 — Workspace Projection & UX Wiring

V0.6.3 completes the rich EPIC 2 manager-facing projection on top of the operational mutation facade introduced in V0.6.2.

## Boundary

The stable `SalesWorkspaceReadModelInterface` remains unchanged. Rich UX projections are exposed through `SalesWorkspaceOperationalReadModelInterface` and `MysqlSalesWorkspaceOperationalReadModel`.

- Sales owns business semantics and manager-facing projections.
- Kernel owns generic actions, approvals, queueing, execution and audit.
- UI invokes canonical Sales/Kernel operations and never mutates persistence directly.

## UX slice

- **Today 2.0**: My/Team scope, attention reasons, pending approvals, complete/reschedule activity actions.
- **Lead Inbox**: status, assignment, qualification, deal creation and follow-up actions.
- **Pipeline 2.0**: owner/risk/priority/source/search filters, stage value/weighted value/average age, richer deal cards and stale-stage handling.
- **Deal Workspace 2.0**: first-class Next Action, communications thread, Send Message, approvals and COS action lifecycle controls.
- **Deals**: owner/priority/source filters plus explicit attention reason.
- **Director**: current-state funnel cohort, pipeline health, manager operational load and pending approval count.

## Projection caveats

`days_in_stage` currently uses `updated_at` as an approximation. Accurate stage duration requires a dedicated stage-history projection and is intentionally not fabricated here.

Director `Funnel` is explicitly a **current-state cohort**. It is not historical transition conversion. Historical conversion belongs to a future stage-transition analytics projection.

Revenue influenced remains dependent on measured `Business Outcome` attribution. Touching a deal is not revenue attribution.

## Concurrency

`ChangeDealStage` returns canonical `concurrent_stage_change` when optimistic stage mutation loses a race. The workspace treats that state as stale UI and reloads current state instead of pretending the stage mutation succeeded.
