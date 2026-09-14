# Sales V0.6.5 — EPIC 2 Final

Sales V0.6.5 closes the manager-facing **Sales Workspace UX** epic. It does not add another Sales runtime. The workspace remains a business-facing surface over Sales semantics and Kernel orchestration.

## Final operating loop

`Lead → qualification → Deal → next action → approval/execution → result → next attention signal`

A manager can stay inside Sales Workspace for the main operational loop:

- `/sales` opens **Today**;
- Today exposes own/team attention, approvals and activity lifecycle actions;
- Lead Inbox supports assignment, qualification/disqualification, Deal creation and follow-up;
- Pipeline uses configurable stages and canonical `ChangeDealStage`;
- Deal Workspace provides communications, manager operations, approvals, COS actions, Intelligence and Timeline;
- Director exposes current-state funnel, pipeline health, manager load/discipline and measured COS outcomes.

## V0.6.5 additions

### Global Sales Search

Every Sales workspace page now has one tenant-scoped search surface for:

- Deals by customer, title, public ID, phone or email;
- Leads by name, phone or email;
- Persons by name, phone, email or Telegram.

Search is a read projection. It is deliberately part of `SalesWorkspaceOperationalReadModelInterface` and does not widen the stable runtime read contract.

### Dependency boundary

The Web/API layer no longer constructs `MysqlSalesWorkspaceOperationalReadModel` directly. Bootstrap registers `salesWorkspaceOperationalReadModel`; interfaces depend on the Application contract. Infrastructure remains replaceable and, more importantly, stops leaking upward because somebody got impatient for six lines of DI.

### Director metrics

V0.6.5 adds metrics that can be derived honestly from existing Sales data:

- at-risk revenue;
- stale deals (7d using the current stage-age approximation);
- lead response time by manager;
- follow-up completion rate by manager.

The funnel remains explicitly **current-state cohort**. Historical stage conversion is not claimed until a dedicated stage-history projection exists.

### Interaction states

Approval/action failures are now rendered inline instead of `window.alert`. Async controls expose loading/success/warning/error states. Canonical `409 concurrent_stage_change` still forces a reload of current Deal state.

## EPIC 2 Definition of Done

| Capability | V0.6.5 state |
| --- | --- |
| `/sales → Today` | Done |
| Today own/team work + approvals | Done |
| Lead Inbox operational actions | Done |
| Configurable Pipeline + canonical stage mutation | Done |
| Deal Workspace manager operations | Done |
| Communications / Send Message | Done |
| COS approval + action lifecycle | Done |
| Intelligence + unified Timeline | Done |
| Director operational analytics | Done, with truthful projection caveats |
| Global Sales Search | Done |
| Tenant / manager boundaries | Done at API/read projection boundaries |
| Conflict / async UX states | Done |
| Regression contract | Done |
| Browser smoke | Added; authenticated environment required for execution |

## Explicit caveats

1. `days_in_stage` and `stale_7d` currently use the Deal update timestamp as an approximation. A historical stage projection is still required for authoritative stage-duration analytics.
2. Director stage counts are current-state distribution, **not historical transition conversion**.
3. External message delivery depends on the configured CRM/message provider; the Sales operation and local communication record are canonical, but provider capability remains integration-specific.
4. `tests/browser/sales_workspace.mjs` is mutation-free and requires an authenticated base URL/storage state. CI syntax-checks it; a dev/release environment can execute it against a deployed workspace.

## Boundary that remains unchanged

Sales owns business semantics and workspace projections. Kernel continues to own generic Rule, Agent, Action, Policy, Approval, Queue, Audit and execution mechanisms. V0.6.5 introduces none of their Sales-specific duplicates.
