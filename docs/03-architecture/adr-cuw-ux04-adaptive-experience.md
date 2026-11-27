---
title: ADR — UX-04 Adaptive Progressive Disclosure vNext
description: One authorized business state; deterministic display depth and scoped preferences.
status: isolated-pilot
updated: 2026-11-27
kind: architecture
---

# UX-04 — Adaptive Experience

## Reuse / adapt / new

- **Reuse** the same Application Query/Command, UIActionResolver, Workspace provider, Property Bulk Intake and Federation Plan/Workflow; no business repository or execution path is added.
- **Adapt** ExperienceContext and ExperienceSemantic additively: optional fixed trusted signals and server-derived workspace/entity/operation/surface context; existing section arrays, v1 schema, action filtering and serialization remain compatible. Resolver evaluates capabilities before visibility, then critical/always/contextual triggers, explicit expansion, mode default. Resolved decision actions remain primary; duplicates and permissionless/disabled mutations never become controls.
- **Reuse** FederationExperiencePreferenceStore and its existing tenant/user scoped tables. The account default applies consistently across the Unified pilot screens; expanded sections are scoped to workspace and positively read-back entity/batch. Legacy Federation workspace overrides retain their existing behavior. No schema migration required. Corrupt/unavailable settings fall back to Result without granting authority.
- **New adapter** AdaptivePagePresentation is an SSR-only adapter for the canonical resolver and existing preference store, not another adaptive engine. Same-page CSRF-protected POST accepts only fixed mode or section expansion operations and redirects to the verified current internal route; GET reads native state. Browser cannot supply trigger, capability, tenant, owner or redirect authority. Mode POST never calls an Action or Plan mutation.
- **Shared Twig controls** render one page model, not three UI implementations. Desktop/mobile use wrapping CSS, accessible buttons and native server forms; no AI or JS dependence. Critical approval, risk, failure, stale/unknown and permission signals remain visible; a triggered section cannot be collapsed. Expert exposes identifiers only from existing authorized reads, not raw payloads or arbitrary logs.

## Scope and acceptance

| Pilot | Result | Process | Expert |
|---|---|---|---|
| R1 Home and Property/Sales workspace | Same search/summary, canonical link | Activity, related entities and secondary action affordances | Verified entity/workspace identifiers, correlation for visible activity |
| R2 Property query and human create | Same typed read and canonical authorized form | Action readiness reasons | Contract identity/status/risk and entity reference |
| R3 Property bulk | Native per-item progress, unknown outcome, review/approval gate | Lifecycle state and per-item truth | Same batch/Goal/Plan identifiers and snapshot digest; explicit JSON input available |

Activity lifecycle is the provider's status, not evidence of business success. A planned bulk batch does not prove an actual pending or granted approval: the warning describes the mandatory independent approval gate and routes to canonical Goals instead of inventing approver state. Verified native item count is not a final Goal evaluation.

Tests: unit matrix for all ten signals, rule behavior, permission fence, action set/mode parity, decision visibility, deduplication. Existing isolated browser R1/R2/R3 suite extended with mode/expansion persistence, CSRF/forged input/role rejection, canonical entity and progress parity, no Action/Run/native writes from preference changes, and screenshot evidence. No PASS asserted until jobs run on the PR SHA.

## Rollback and residual scope

Revert the adapter/routes/templates without data migration; persisted presentation preferences may remain safely unused. Existing Domain writes/eligibility remain canonical. Full UX-04 acceptance still depends on isolated browser/runtime evidence, cross-device/tenant checks, accessibility and existing R3 worker/receipt gate; no production enablement. UX-05 operation aggregation and UX-07 Goal composition remain separate work.
