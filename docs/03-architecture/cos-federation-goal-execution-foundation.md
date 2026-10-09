---
title: COS Federation & Goal Execution Foundation — Package A
description: P0 контракт виконання бізнес-цілей і перевірки результатів; без вимоги будувати повний Progressive Disclosure.
status: active
updated: 2026-10-09
kind: architecture
---

# Package A: COS Federation & Goal Execution Foundation

**Priority:** P0. **Scope:** canonical Federation, owned capabilities, Action/Workflow/Agent governance, Goal → Plan → Run → verified Outcome, tenant isolation, operational audit and minimal Experience contracts. **Out of scope:** complete Result/Process/Expert UI, personalization, behavioral analytics and AI-driven adaptive UI; see [Package B](./cos-progressive-disclosure-adaptive-experience.md).

## Architectural ownership

Kernel/Platform retains identity, tenant, command/event transport, human approvals, Policy, Workflow, Action and Agent dispatch. Each Domain owns its business semantics and authoritative Outcome. Federation validates and coordinates an immutable approved plan but never generates its own arbitrary domain outcomes. Experience sees read-only canonical projections and metadata; no view grants permissions.

## Canonical execution model

```text
Authenticated request / user Goal
    ↓
GoalSpecification + versioned success criteria
    ↓
ExecutionPlan (capability registry + pinned schema/version + budget/risk)
    ↓
Independent Policy / Approval
    ↓
ExecutionRun → deterministic Step → canonical Action/Workflow
    ↓                  ↘ failure / blocked / retry / manual resolution
Domain native result     Recovery and operator intervention
    ↓                  ↗
Verified Domain Outcome + scoped evidence → OutcomeEvaluation
    ↓
Single Goal state / execution state / source references
```

Federation stores one authoritative Run with step state, provenance, immutable approvals and evidence. A completed workflow does **not** equal a successful business goal; unverifiable evidence is explicitly unknown and must not be interpreted as zero or success.

## Minimum Experience contracts included in A

- Existing `ExperienceContext`: trusted organization/user, mode and capability snapshot; not an authorization source.
- `ExperienceProfile`: versioned, tenant/user-scoped preference snapshot; persistence remains `cos_experience_profiles`.
- `ExperienceState`: workspace/entity-scoped view state; persistence remains `cos_workspace_experience_states`.
- `ExperienceSemantic` + `ExperienceSemanticsCatalog` v1: declarative purpose, kind (`workspace`, `ui_action`, `component`), role (`primary`, `supporting`, `contextual`, `expert`), recommended mode, priority, contextual triggers, disclosure policy, optional capability reference.
- Metadata cannot carry credentials, arbitrary PHP/JS snippets, command arguments, grants or deny rules. `UIActionResolver` and Policy/Approval/tenant checks remain separate, authoritative and enforced on actual requests.
- Existing proof-of-concept mode UI is retained without making visual completeness a Package A acceptance gate.

## Required P0 exit evidence

1. Canonical capability owner, schema version, tenant activation and executable Action/Agent bindings verified against registry; no cross-domain direct business writes.
2. Goal → approved immutable Plan → one durable Run → independently reconciled Action receipts → native Domain outcomes → evaluation. No self-approval or fabricated success.
3. Failure injection: malformed input, stale approval, cross-tenant reference, timeout, ambiguous external response, resume/manual decision, duplicate action, stale/forged evidence all fail safely.
4. Representative multi-Domain golden path with actual business sources. Operator can inspect Goal, Plan, Step, receipts, approvals, errors, outcomes and recover a blocked step through existing expert/admin facilities.
5. Presentation DTOs and semantics contracts versioned, unit-tested and immune to authorization escalation. UX is not used as a P0 workaround for execution incompleteness.
6. CI, runtime, database, browser/security testing, targeted migration and deployment rollback runbook green for the accepted commit. Production readiness remains a separate gate.

## Concrete reference acceptance scenario

> Знайди 50 потенційних клієнтів, перевір їх, передай у CRM і підготуй комерційні пропозиції.

Expected chain: Growth market discovery (50 target candidates, sources, dedupe) → quality/qualification (identity & evidence) → approved CRM intake into Sales → Documents preparation of proposals → explicit final Goal evaluation. Every transition must record input/output identifiers, tenant, policy and potential manual exception. The workflow must support partial failures without re-running previous external side effects.

**Important:** current Federation PR includes slices for Sales, Growth feedback, Research and Documents signature requests, but does **not** yet prove this full 50-lead Growth → Sales → proposal production golden path. The scenario is the remaining Package A gate, not a claim of completion.

## Release boundary A → B

Package A is eligible for acceptance only once the above P0 golden path is green in a controlled tenant. Deliver an immutable Goal/Plan/Run/Outcome read projection, Step/action controls with authorization, canonical failure/recovery statuses, and Experience Semantic descriptors as a published versioned interface.

Work not required to unlock B: perfection of all Domains, P1 tracing, predictive architecture explorer, polished mobile Expert UI. No new Kernel or Domain solely for Progressive Disclosure.

## Automated golden-path execution readiness preflight (2026-10-09)

`FederationReferenceScenarioReadiness::inspect(TenantContext)` checks
**real, registered, typed, tenant-executable Action handlers** for the
Growth → native Sales → Documents reference scenario. It is a read-only,
manager-authorized *structural preflight*, **not business Outcome evaluation**.

Five actual executable Action transitions, each with an immutable approved
input, canonical Policy and independent human Approval, are required:

1. `growth.market.discovery` (Growth): source a bounded tenant-owned market
   universe through the existing discovery service, preserving deduplication
   and the partial/in-flight recovery status.
2. `growth.candidate.qualify` (Growth): evaluate an already **scored**
   Candidate against an **active revisioned policy**, recording
   `qualified` / `monitor` / `disqualified` truthfully.
3. `growth.handoff.prepare` (Growth): persist the approved
   `qualified → ready_for_handoff` transition and a Sales-targeted
   package with expected value, play and recommended action.
4. `growth.handoff.target.sales` (Growth coordinating a Sales-owned
   write): dispatch the existing idempotent Growth handoff. Its registered
   **SalesGrowthHandoffTarget** invokes the canonical
   `SalesWriteService::createLead` and persists a Sales Lead ID in the
   accepted Growth handoff attempt. **This is the CRM lead creation.**
   A second `sales.lead.create_from_growth` Action would risk a duplicate
   and is deliberately **not** registered. Structural preflight additionally
   requires the **Sales module enabled for this tenant**.
5. `documents.proposal.prepare` (Documents): resolve the future Lead ID
   **from the accepted tenant-owned handoff receipt**, then check the
   Sales-owned read model before generating an unsent, tenant-scoped
   Document from a native template and attaching it to that Lead.

`action:...` bindings must have exactly one registered Domain-owned handler;
a string in `module.php` is insufficient. An inactive tenant module,
inactive Sales dependency or unknown capability fails closed. The remaining
unit evidence is in:
`tests/unit/federation_reference_scenario_readiness.php`,
`tests/unit/federation_reference_scenario_executable.php`,
`tests/unit/federation_reference_action_payloads.php`,
`tests/unit/federation_growth_action_bindings.php`,
`tests/unit/federation_growth_prepare_handoff_action.php`,
`tests/unit/federation_growth_sales_handoff_action.php` and
`tests/unit/federation_documents_proposal_draft.php`.

### Runtime integrity and manual intervention

- A market-discovery batch completing is **not** proof of 50 distinct,
  verified, qualified prospects. A disqualified candidate is never reported
  as qualified.
- Qualified candidates must be explicitly prepared before dispatch; the
  persisted `ready_for_handoff` state cannot be skipped.
- Failed, partial or ambiguous external operations are blocked and require
  operator receipt reconciliation; no automatic retry or second Sales write.
- Documents generation and attachment have separate deterministic
  idempotency subkeys. An incomplete attachment must be reconciled, not
  silently accepted as an intact proposal.
- A saved native Document has status `active` in the existing Documents
  lifecycle; `prepared_not_sent` describes the Action's commercial
  **unsent-draft** outcome, not a fictitious native `draft` status.
- Completed Actions and a terminal Federation Run do **not** prove a
  business Goal. The preflight explicitly reports
  `business_outcome_verified=false` until Domain-trusted read-model
  evidence supports each Goal criterion.

### Outstanding Package A acceptance requirements

This code implements five **single-subject canonical Action bindings**, not a
complete 50-subject autonomous campaign. Prospect and Candidate IDs are
discovered *after* a market scan, while the approved Plan currently pins
immutable step inputs. The full Goal needs a separately reviewed,
deterministically bounded, human-approved fan-out/subplan protocol rather
than mutating approved Plans or inventing unknown future IDs.

A real tenant-scoped acceptance test must run market discovery on a
controlled source, verify 50 **deduplicated and sourced** prospects, qualify
the selected Candidates, approve and reconcile each Growth→Sales handoff,
verify **native Sales Lead IDs exactly once**, prepare actual template-backed
proposals and measure persisted outcomes with Domain-owned evidence. The
current readiness preflight and isolated Action handler tests do not replace
that end-to-end test.

Package B modes are not acceptance criteria for this foundation. Production
enablement, CI verification at **the latest branch HEAD**, controlled tenant
rollout and merge remain separately gated.


## Bounded candidate fan-out after source discovery (2026-10-09)

`FederationCandidateFanoutService` implements the next **explicit manager-operated** step without mutating an approved Federation Plan. It attaches 1–50 candidate-specific **proposed child Plans** to the same existing Goal, so future Growth Candidate IDs never need to be invented in the original market-discovery Plan.

**Read-only preview:** `GET /api/v1/federation/fanout/preview`, authenticated tenant manager. Parameters: `goal_id`, `source_run_id`, `source_step_id`, `requested` (1–50), `policy_id`, `policy_revision`, `template_id`, `expected_value`, `recommended_play`, `recommended_action`. No Actions/Plans are created.

**Explicit draft creation:** `POST /api/v1/federation/fanout/propose`, the same fields as form fields, mandatory session CSRF. Creates the candidate-specific Plans in one DB transaction, all-or-nothing. The `state` remains `proposed`; **each plan requires independent native Plan approval, and each Action requires its own human approval**. No scheduler, bulk Action execution, automatic CRM writes, or proposal sending is part of this step.

### Trusted provenance

- Confirm completed Federation source Run and its **approved, version-pinned** `growth.market.discovery` Step for the **same Goal and tenant**.
- Re-attest the completed **first-attempt** canonical Action via the existing receipt reconciler, then verify its exact source Universe and federated idempotency key.
- Derive the deterministic native `GMRN-…` market discovery run ID from that key, not from a user-entered string. Native Growth Run must be completed and fall within `universeBrief`'s bounded latest-run snapshot.
- Source memberships must be tenant/universe-bound, deduplicated across Candidate, Account and source fingerprint, and actually observed within the native run's start/end window. Accounts **without** a Candidate are ineligible.
- Resolve each Candidate through `GrowthApplicationBoundary::viewCandidate`. Only `scored`, Sales-targeted, account-owned Candidates with stored score/rationale are eligible. A completed source scan is **not** 50 qualified Candidates.
- Require the actual **SalesGrowthHandoffTarget** prerequisites: Buying Committee assessment, exactly one champion, valid full name and email identity, and a tenant-scoped contact-account link.
- Resolve an **active qualification policy revision** and an **active tenant-owned Documents template** from existing Domain/Platform repositories before any proposed Plan can be saved.
- Verify all four candidate Action capabilities are tenant executable and the Federation and Sales modules are enabled. The Goal must allow those capabilities and its original actor identity must be usable by the existing integer-actor Growth runtime.

### Immutable plan and recovery policy

The pure `FederationCandidateFanoutPlanner` creates one deterministic `plan-<hash>` per tenant/Goal/Candidate, **independent of repeated source scans**; each contains four ordered Action Steps:

1. `growth.candidate.qualify`
2. `growth.handoff.prepare`
3. `growth.handoff.target.sales` (this performs the native Sales Lead creation **once**)
4. `documents.proposal.prepare` (resolves that Lead from the accepted handoff)

Each immutable Plan JSON includes a strictly checked `lineage` with source Federation Run and Step IDs, native discovery run ID, Candidate/Account IDs, hashed external identity and SHA-256 evidence fingerprint. Repeat discovery of an existing Candidate within the same Goal is rejected through the same deterministic Plan ID, rather than creating a second Sales intake. Attempted re-proposal with the same deterministic Plan ID is rejected, and a transactional failure rolls back the entire proposed batch. The existing approved-plan hash covers lineage as well as Action inputs.

If fewer than `requested` Candidates pass all gates, the operation returns `insufficient_scored_candidates` and **creates no Plans**. A `monitor` / `disqualified` decision can still stop a candidate's subsequent handoff under normal Growth guards. The preview explicitly returns `business_outcome_verified=false`; no result is counted until tenant-owned native business evidence can be reconciled.

**Still required for Package A acceptance:** a controlled tenant integration run with 50 distinct truly sourced candidates, independent approvals and native Growth/Sales/Documents receipts, an aggregated trustworthy Goal Outcome across the child Runs, operator UX for reviewing/selecting the proposed batches, CI against the final commit, and branch conflict resolution before merge. Structural preview and unit tests are not a substitute.
