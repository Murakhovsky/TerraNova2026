# CUW-B · UX-05 Operations Center — initial vertical slice

Issue #520; parent #518. Pinned base: `10fc649df6aafdca432ec329ebd737f6a4abd1ec`.
Normative references: Master Spec §10, §19, §20, AC-02/04/06/07/08/11/16;
existing async-operations-platform, realtime-platform, unified-action-platform,
cos-execution-contract-consolidation-p0, audit-history, UX-01/02/03 ADRs.

## Reuse / adapt / new

- **Reuse:** tenant manager/access/manage policy, AgentRunReadModelInterface,
  AsyncOperationReadModelInterface, WorkflowExecutionStoreInterface (step read),
  FederationCanonicalReceiptInspector (independent receipt attestation),
  UIActionResolver + ApprovalAuthorityInterface + OperationsMutationCommand (human approval),
  FederationExperiencePreferenceStore (existing mode default), EntityRef and EntityLinkResolver,
  canonical Workspace Shell, private Mercure/Turbo subscription.
- **Adapt:** isolated read adapter for Action + approval + Workflow + native Product CSV batches:
  existing global Action projection lacks a narrow list/detail port; no raw payloads,
  worker claim tokens or audit rows. Every query and related join uses organization.
- **New:** only presentation enum/projection, SSR page, isolated route file,
  snapshot refresh controller and contract tests; no migration, table, worker, scheduler,
  alternate execution authority, or external provider activation.

## Route and integration ownership

`/workspace/operations` is protected by the existing workspace firewall and
explicit controller role/permission checks. The new *isolated* route resource is
loaded by MicroKernelTrait. No edit to shared routes.yaml/services.yaml, Shell,
translations or workflows. Integrator: add Operations Center navigation/command
entry pointing at this route; confirm route and DI registration; add functional,
browser and DB acceptance coverage to combined gates. Rollback: remove route /
navigation, no persisted business state or schema changes.

## Status contract

- Proposed/staged/waiting are blocked or queued as native semantics permit.
- Approval pending is not Action execution; expired/invalid lifecycle is blocked.
- Complete Agent, job, Action, import or unknown Workflow = completed_unverified.
- Only an independently re-attested Federation Workflow with nonempty fully
  verified step receipts receives completed_verified; this describes execution
  evidence, not Goal outcome. Goal metrics/outcome remain authoritative in Goals.
- Ambiguous state is external_outcome_unknown; uncertain/expired claimed steps
  require reconciliation. Unknown status is blocked. Scheduled retry is queued
  with a retry indicator; no fabricated progress, no optimistic mutation.
- Action attempt metadata and canonical step receipt references are shown; native
  batch items retain per-row status/error code and product reference. No payload
  or provider secret surfaced. Result/Process/Expert use the same rows and
  approvals, critical statuses always visible.

## Evidence and remaining gates

| AC / area | Initial implementation | Verification still required |
|---|---|---|
| AC-02 / AC-04 | tenant manager and permission gate, tenant-bound joins, exact direct detail; same facts across modes | MySQL two-tenant/role browser negative tests |
| AC-06 / AC-07 | canonical reference, no fake success, explicit unknown and reconciliation | durable live readback including post-cutover/legacy Workflow |
| AC-08 | approval only if resolver + existing ApprovalAuthority permits; server reread and CSRF; step-up controls not exposed | independent approval, concurrent duplicate POST, rejection and critical step-up E2E |
| AC-11 | independent source failures, private Mercure signal then SSR refresh, 30s polling, stale/offline indicator | browser reconnect, timeout, provider-down and stale race tests |
| AC-16 | SSR list/cards, semantic labels, modes, mobile responsive shared components | 390px browser screenshots + a11y + locale |
| R3 | Workflow run steps, latest Action receipt, import row receipts, entity/correlation views | actual approved batch and external outcome comparison in staging |

Limits: 50 recent records per source; context filter is a bounded history window,
not a complete archival search. Exact detail is independent of that window.
Import items limited to first 100, full report in native Product page.
Only canonical Product CSV shown as explicit import source; all supported async
job types are listed. Complete Action/Agent/import business proof requires
domain-specific evidence adapters. No replay of uncertain side effects; retry and
reconcile mutations intentionally unexposed until canonical UIActions and
admission/step-up contracts exist; existing recovery diagnostics are linked.
Activity stays in Activity Center; Audit link only for admin in this surface,
legacy access unchanged. No production/deployment/worker gate authorized.

Test scripts: `php tests/unit/cuw_ux05_operation_status.php`,
`php tests/unit/cuw_ux05_operation_snapshot.php` (repo composer dependencies).
Local environment has no PHP/Composer; JS syntax and git whitespace checks only.
CI and integrated runtime/browser acceptance are outstanding. This is a start
of the independent workstream, **not** D3/D4/D5 or completed Issue #520.
