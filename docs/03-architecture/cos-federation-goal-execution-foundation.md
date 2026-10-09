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
