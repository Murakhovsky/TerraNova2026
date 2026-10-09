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
