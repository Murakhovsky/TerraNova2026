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

## Iteration 2 — independent workstream B1–B8

This paragraph supersedes the initial 50-record history limitation above.
The registry now uses a per-source keyset cursor on immutable
`created_at,id`, 50 records per source plus one lookahead. A deep link is
independent of the history page. A cursor is navigation data, not an authority:
each SQL page and direct lookup still binds the authenticated organization.
Exhausted sources are skipped; unavailable sources retain their cursor when
another page exists. The UI explicitly reports empty filtered pages, and
provides next and first-page navigation. Correlation and exact EntityRef
(Action/approval/Agent/import/Goal) are applied before pagination; import
correlation is joined only by the native Action idempotency key AND native batch
target. Pending approvals have a pre-pagination predicate with expiry and
Action lifecycle guards. Other projected status filters and async EntityRef
are evaluated after the current page; users can advance to older pages.

Workflow detail projects the persisted step list against the immutable
definition's checked digest and canonical dependency graph. Dependency/step
mismatches fail closed. Prerequisite state, revision, attempt, effect level and
receipt reference are displayed without exposing input, claim token, or
idempotency key. Action detail includes up to 101 recent attempts (status,
receipt identity, timing, error presence); Agent detail links its tenant-owned
Actions; Product import exposes recorded per-row receipt key, product identity
and safe error code. Goal and native import/Workflow/Action links retain the
tenant boundary. A completed state is never upgraded based on a receipt
reference alone.

Selected non-healthy Workflow details use the same canonical recovery
inspection as the existing API, including the kernel Agent/Tool variant and
the expected Run revision. The projection displays disposition/reason and
up to 50 recorded recovery requests, with the native worklist, inspect and
request-history GET links. No request, approval, apply or retry is issued from
GET. Canonical recovery retains its two-person CAS and no-external-replay
contract. Approval inbox exposes assignee, expiry and decision identity/time;
the existing resolver and authority still gate each POST on a fresh canonical
row and CSRF. A critical approval requiring step-up remains unavailable;
a checkbox is not step-up authentication.

Business outcome is separate: selected Goal-linked Workflow reads
`FederationGoalStore::latestTrustedEvaluation` (stored run-scoped
`domain_read_model_v1` only). Displays historical result, evaluation ID and
recording time, or explicit absence/unavailability. No evaluator or domain
writer is called by the page. This does not attest undocumented domains,
non-Goal jobs, or currently changed business facts.

Realtime now coalesces invalidations during an in-flight read and defers them
while a decision form is focused. Missing SSR content, partial evidence,
timeout or offline never grants a live badge. A successful polling refresh
without a signal remains stale (realtime not attested). The controller unit
simulation covers repeated signals, missing or partial snapshots, offline and
reconnect, watchdog timeout, and focused decision forms.

### Integrator-owned prerequisites and remaining acceptance

| Area | Implemented on independent branch | Remaining before acceptance |
|---|---|---|
| B1 registry | Keyset history, six canonical sources, exact detail, correlation/EntityRef/Goal | Real MySQL pagination, performance/index plans, unmigrated legacy and other native imports decision; status and async EntityRef search currently traverse pages |
| B2 Workflow | Revision, attempts, pinned dependencies and blockers, step receipt details | Actual durable Workflow, concurrent transitions, missing/drifted receipt fixture |
| B3 execution control | Read-only canonical recovery assessment/history and existing queue/recovery source links | Shared UIAction contracts for eligible retry and recovery request/approve/apply, admission and independent approval; no direct retry button in UX-05 |
| B4 approvals | Pending inbox before pagination, assignee/expiry/history, authority/CSRF/reread gated actions | Canonical server-backed critical step-up contract, concurrent duplicate submission fixture |
| B5 outcomes | Stored trusted Goal evaluation, separate technical evidence and per-item receipt IDs | Additional domain-specific outcome contracts and live staging native readback; no business-success inference |
| B6 realtime | Signal + SSR, polling, partial/offline/focus/race unit simulation | Actual private Mercure reconnect/provider-down browser proof |
| B7 integration | Isolated route, canonical shell, links to Activity, Goals, privileged Audit/Control, workflow/approval/recovery | Integrator adds shared shell nav and shared UX-04/06 trigger/notification descriptors; verifies tenant firewall and service wiring |
| B8 acceptance | PHP status/snapshot/history/step tests; JS simulation; staging read-only browser test | PHP/Composer/DB, safe write fixture for approve/reject and recovery CAS, CI same integrated SHA, mobile 390px, staging D5 |

Integrator must register new retry/recovery UIActions through the existing
registry/resolver with exact resource ID and tenant/actor permission checks,
using the existing command/API contracts only. Recheck eligibility and CAS
revision on POST. Critical confirmation must use the existing server-backed
step-up mechanism when available; no synthetic step-up. Preserve no replay
when the external effect is uncertain. Do not expose direct Action/queue
replay merely because a row is marked FAILED or canRetry.

Local executable checks:
- `node tests/unit/cuw_ux05_realtime.mjs`: passed.
- `node --input-type=module --check < symfony/assets/controllers/operations_snapshot_controller.js`: passed.
- `node --check tests/browser/cuw_ux05_operations.mjs`: passed (syntax only).
- `git diff --check`: passed.
- PHP tests and Symfony Twig/DI lint: not executable in this workspace (PHP
  and Composer absent). Browser script requires a disposable staging
  environment, manager/member in tenant A, a known foreign operation from
  tenant B, and fixture approval ID. Configure `UX05_E2E_*` according to the
  script and run `node tests/browser/cuw_ux05_operations.mjs`; captures 390px
  result/process/expert screenshots and tests role/tenant isolation, invalid
  CSRF, offline/reconnect without successful mutations. This is a test plan
  and runnable harness, not proof of executed browser acceptance.
- Integrator should wire the JS simulation into the combined CI gate; existing
  PHP unit discovery does not include mjs. No shared CI/config modifications.

No D3/D4/D5 or Issue closure claimed. No remote publication or deployment
included in this iteration.

## Iteration 3 — fenced recovery UIAction contribution and complete status candidate filtering

This supersedes the earlier statement that UX-05 contains only read-only
recovery diagnostics. The independent branch now includes the actual
**request → second-manager approval → apply** SSR forms, resolver-gated POST
adapter, and exact canonical recovery candidate reads. These controls are
intentionally **unavailable until registered by the integrator**; neither
Twig nor the POST controller can synthesize/enable an unregistered descriptor.

- `RecoveryCandidateRead` checks the current Run revision and fingerprint of
  the immutable graph; reads only its relevant waiting, ambiguous, or expired
  claimed Steps. A live claim is never offered. Native step binding/effect is
  checked; current pending decisions are looked up independently of history
  pagination. A read-only reset is offered only to a pinned `read:` binding,
  side-effect level none, no persisted receipt and no native Action key.
  Kernel Agent/Tool recovery exposes verified receipt completion only.
- `RecoveryActionPlan` makes an ID from exact Run revision, Step revision,
  resource and resolution. Request forms collect independent receipt and
  rationale; approved/apply forms show canonical recorded rationale and
  receipt. The requester never sees their own approval button. Unsupported
  quarantine/cancel, generic Action/Agent job replay, and critical mutations
  requiring step-up stay unavailable. This layer is presentation filtering,
  not a new admission Policy: the canonical writer rechecks all eligibility,
  grants, two-person approval, graph, CAS, evidence and prior receipts.
- `OperationsRecoveryActionProvider` contributes exact resource descriptors
  through `ActionProviderInterface`, context `workspace/operations`, entity
  `operations.workflow:<run-id>`, permission `cos.tenant.manage`, placement
  `workspace.secondary`. `OperationsRecoveryUI` intersects with the actual
  `UIActionResolver` output and rereads current canonical facts. Unregistered,
  stale, disabled or step-up descriptors do not yield a button or a POST grant.
- The isolated POST route
  `/workspace/operations/workflow/{runId}/recovery` checks authenticated manager,
  access/manage, CSRF, explicit confirmation, exact current descriptor and
  resource; delegates to existing `DoctrineWorkflowRecoveryManager` or
  `DoctrineKernelExecutionRecoveryManager`. Same actor and fenced request
  has a stable request ID; duplicate/stale POSTs do not create a new decision.
  Native owner remains sole authority; a failed/unconfirmed POST has no
  optimistic business success. Successful POST redirects back to canonical
  readback. A read-only reset may resume the original approved Workflow through
  its existing scheduler; it is not direct external replay.
- All six sources now preselect native status candidates before keyset
  pagination. Actual projected status still controls UI; verified/unverified
  completed Workflow deliberately share the same SQL candidates before
  independent receipt attestation. Async EntityRef still uses the existing
  typed projection and is checked per page. Receipt links to canonical Action
  and native Product are available in step/import detail, not as proof by fiat.

### Required shared integration (in parent integrator PR only)

Register `App\Web\Operations\OperationsRecoveryActionProvider` (service ID
`operationsRecoveryActionProvider`) with the shared UIActionRegistry, consistently
with `ActionProviderInterface` / `UIActionResolver`. It is already autoconfigured
as a web extension provider by existing Symfony tags. Because Operations is
global, integrate it as a **platform-global** contribution (e.g. shared registry
additional platform providers), NOT merely as a Federation module extension
which disappears for tenants with Federation disabled. The existing generic
module contribution mechanism is exercised by a contract-mocked unit test;
actual global registration and DI lint belong to the integrator. Preserve
existing UIAction uniqueness, permission resolution and context checking.
Add the shared Shell Operations navigation to the isolated GET route.
Critical approval requires a genuine server-backed step-up authorization
contract; generic queue replay requires explicit safe owner classification.
Neither is invented in UX-05.

### Acceptance artifacts added in this iteration

- `php tests/unit/cuw_ux05_recovery_actions.php`: tests stable/stale revisions,
  independent approval, duplicate/request lifecycle, receipt/mode denial,
  and real shared resolver using a contract-mocked registered/unregistered
  provider, permission and tenant negatives (not executed here).
- `php tests/unit/cuw_ux05_status_predicate.php`: tests predicate bindings and,
  where PDO SQLite is provided, SQL fixture cases covering statuses, expired
  approvals, Workflow uncertainty and job retry. Actual MySQL integration
  still required. Independently extracted SQL literals passed 14 SQLite cases
  locally; this is not PHP or MySQL execution evidence.
- `node tests/browser/cuw_ux05_recovery.mjs`: opt-in staged mutation fixture
  with two distinct authenticated managers, exact pre-existing Run and Step,
  and independently verifiable receipt. Requires `UX05_E2E_STAGING=1`,
  `UX05_E2E_MUTATION_SAFE=1`, corresponding `UX05_E2E_*` credentials and
  fixture identifiers as specified in the script. Checks CSRF, confirmation,
  one canonical petition, self-approval denial, independent decision, apply,
  canonical request readback and duplicate POSTs. No production provider
  execution authorized. Not executed without a disposable staging fixture.
- JavaScript syntax and realtime simulation passed; route YAML parsed and
  template route/block consistency checked; git diff whitespace passed.
  PHP, Composer, MySQL/DI/Twig runtime and browser acceptance unavailable here.

Remaining: shared global UIAction + navigation wiring, critical step-up,
explicit safe queue retry classification, full domain outcome coverage,
integrated CI and staging evidence at exact SHA. No merge/deploy or Issue
closure claimed.
