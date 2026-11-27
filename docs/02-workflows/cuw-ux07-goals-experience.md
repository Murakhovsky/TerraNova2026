# CUW-D · UX-07 Goals Experience (#522)

Baseline: `10fc649df6aafdca432ec329ebd737f6a4abd1ec` · independent stream.
Master Spec §12, §19, AC-01, AC-02, AC-14, AC-16.

## Reuse / adapt / new

- **Reuse:** WorkspaceShellFactory, existing tenant manager and session CSRF checks,
  FederationExperiencePreferenceStore, GoalSpecification, FederationGoalStore,
  FederationSmallGoalPlanProposalService, FederationDiscoveryPlanProposalService,
  FederationPlanApprovalCoordinator, FederationCanonicalWorkflowBridge,
  FederationTrustedOutcomeEvidenceResolver and its Domain-owned providers.
- **Adapt:** Goals SSR route and existing controller; add deadline to the Goal list
  projection and immutable dependency/risk details to the existing Plan projection.
  No table, route, DI configuration, policy, worker, agent or execution authority changes.
- **New:** GoalMetricPresentation (UI labels and evidence status, not a second
  registry), semantic HTML fieldset/radio selector, historical 30-day baseline
  via the same evidence resolver, aggregate source descriptions, deadline
  validation, scoped live target selection and replay-safe draft identity.

## Operator path (R5)

1. Describe desired outcome in ordinary language; explicitly choose a supported
   business metric, target and desired date. Baseline is historical, not Goal
   progress; zero is valid only with an actual source observation.
2. Save immutable canonical draft. No draft POST (including replay) runs any Action.
3. For the pilot, select **Створені завдання CRM**, target 1–3. Choose real scoped
   Sales deals from the existing Sales query and propose 1–3 task steps.
   Golden 50 keeps the existing Discovery plan with a tenant-owned active Sales
   Growth Universe and fixed target 50. No arbitrary target IDs or new KPI inputs.
4. Review proposed plan, risk, owner, independent approval request; approval is
   decided in existing Action/Approval. Start only after the completed approval
   receipt and canonical tenant rollout. Policy/worker authorization is rechecked
   by existing runtime. Plan proposal never dispatches business Actions.
5. Advance canonical Workflow, read receipt progress, explicitly evaluate finalized
   Run from trusted Domain facts. Unknown/partial/unsatisfied is not verified success.
   Result/Process/Expert show one Goal and the same blocking states, varying detail.

## Scope and limitations

- Catalog is deliberately bounded to the 10 legacy Federation Goal picker criteria.
  Six temporal metrics use their registered Domain providers; three run-linked
  metrics cannot have an unscoped baseline; Golden 50 is verified by its native
  aggregate, not a fabricated observation. Unsupported persisted criteria display
  an unknown label with exact identity in Expert.
- Sales pilot uses the canonical scoped collection query (up to 200 deals); no
  arbitrary CRM IDs. The real local task evidence source is
  `SalesLocalTaskOutcomeEvidenceProvider` → `MysqlSalesLocalTaskOutcomeReadModel`;
  the Action path is `sales.create_task` → `CreateFollowupTaskHandler` → CRM.
  Availability is not a live provider, policy, worker or delivery certificate.
- The canonical specification already has a deadline. This UI records the desired
  calendar date in Kyiv; **does not** alter Run-derived evidence windows, automatic
  scheduling, SLA, causation or canonical evaluation semantics. A deadline-enforced
  result contract requires a separate Kernel ADR.
- Goal creation is human-controlled without Hermes. No natural-language text or
  generated proposal can silently select an unregistered criterion or mutate state.
- Shared Shell navigation entries, translations, UX-04 adaptive integration and
  UX-05 Operations Center integration belong to the designated integrator.
  Existing Goals route directly adopts the existing WorkspaceShell.
- No external execution, production deployment, rollout activation or merge.

## Acceptance matrix (not a production certificate)

| Case | Evidence |
| --- | --- |
| AC-01 / AC-02 manager + tenant isolation | Existing manager/CSRF guards, canonical scoped Sales query, unit baseline cross-tenant/disabled source checks |
| AC-14 semantic metrics + evidence | `tests/unit/cuw_goal_metric_presentation.php`: actual provider with fake read port, known zero, missing/disabled/failed source, no invented handler/criterion, date |
| AC-16 canonical state and mode parity | Existing R3/S4 browser scenario with human operation label in Result/Process, exact capability only in Expert |
| Draft, invalid CSRF/metric, replay | Extended `tests/browser/ux00_r1_r2_r3.mjs`: DB proof of one draft, no Actions or Runs, persisted deadline |
| Mobile / a11y | 390×844 screenshot + axe scan in browser fixture; pending actual execution |
| Existing outcomes/recovery | Federation persistence smoke renders workspace content block in console context; existing outcome/disclosure/receipt assertions retained |

Run `bash bin/verify fast`, Symfony lint/container/Twig and Runtime,
Frontend + browser E2E, Documentation and L2 integration gates on combined tree.
Complete the real Sales pilot only with authorized manager, independent reviewer,
tenant-enabled live Action handler, admitted worker and verified source.
The fixture/unit checks are not R5 live acceptance.

Rollback: revert the PR; canonical Goal, Plan, Run, approved Action and evidence
records stay untouched. Existing legacy drafts remain readable; the stored
deadline is part of an already-supported specification field.
